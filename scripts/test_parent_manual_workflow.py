#!/usr/bin/env python3
"""DDEV-only Parent Manual regression, using a fresh disposable browser profile.

Run: python3 scripts/test_parent_manual_workflow.py
Requires playwright, /usr/bin/google-chrome, pdfinfo and pdftotext.
Writes synthetic mail to local Mailpit; never deletes existing messages.
Does not launch a print dialog, access user profiles, or contact WHC.
"""
import argparse
import email
import json
import re
import subprocess
import time
import uuid
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright, expect

BASE = "https://reg-form-project.ddev.site"
MAILPIT = BASE + ":8026"
ROOT = Path(__file__).resolve().parents[1]


def run(output):
    output.mkdir(parents=True, exist_ok=True)
    results = []
    def passed(name):
        results.append(name)
        print("PASS:", name, flush=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path="/usr/bin/google-chrome", headless=True, args=["--no-sandbox"])
        context = browser.new_context(ignore_https_errors=True, viewport={"width":1280,"height":900})
        # Print is tested by inspecting the generated frame, never opening a dialog.
        context.add_init_script("window.print = () => { window.__testPrintInvoked = true; };")
        def local_only(route):
            host = urlsplit(route.request.url).hostname
            if host == "reg-form-project.ddev.site" or route.request.url.startswith(("data:", "about:")):
                route.continue_()
            else:
                route.abort()
        context.route("**/*", local_only)
        api = context.request
        cfg_response = api.get(BASE + "/config/parent-manual-fields.json")
        assert cfg_response.ok, "DDEV config unavailable"
        cfg = cfg_response.json()
        fields = [f for s in cfg["steps"] for g in s.get("groups",[]) for f in g["fields"]]
        initials = [f for f in fields if f.get("kind") == "initials"]
        assert len(initials) == 16 and cfg["manual"]["pageCount"] == 33
        assert api.get(MAILPIT + "/api/v1/messages").ok, "Local Mailpit unavailable"
        # Assert PHP routes mail through Mailpit before any submission.
        check = subprocess.run(["ddev","exec","php","-r",'echo ini_get("sendmail_path");'], cwd=ROOT, capture_output=True, text=True, check=True)
        assert "mailpit sendmail" in check.stdout and "127.0.0.1:1025" in check.stdout
        passed("local site and Mailpit guard")
        before_count=api.get(MAILPIT+"/api/v1/messages").json()["total"]
        for revision in [None, "old-manual-revision"]:
            stale={"sessionId":"rejected-stale-test","data":{}}
            if revision is not None: stale["manualRevision"]=revision
            rejected=api.post(BASE+"/api/submit_parent_manual.php",data=stale)
            assert rejected.status==409 and rejected.json()["ok"] is False
        assert api.get(MAILPIT+"/api/v1/messages").json()["total"]==before_count
        passed("server rejects missing/stale revisions without sending mail")
        page = context.new_page()
        browser_errors = []
        page.on("pageerror", lambda error: browser_errors.append(str(error)))
        page.on("dialog", lambda dialog: dialog.accept())
        url = BASE + "/parent-manual-form/"
        page.goto(url)
        expect(page.locator(".pm-initials-display")).to_have_count(16)
        expect(page.locator(".pm-initials-display").first).to_have_text("--")
        expect(page.locator("#pm-revision-notice")).to_have_count(0)
        passed("fresh browser has no revision notice")
        # Seed a legacy encrypted draft in this isolated profile; use the actual
        # client crypto format, and independently verify its encrypted archive.
        secret = re.search(r'const SECRET_KEY_STRING = "([^"]+)"', (ROOT/"js/parent-manual-app.js").read_text()).group(1)
        test_name = "Parent Manual Test " + uuid.uuid4().hex[:8]
        old = {f["name"]:"OLD" for f in initials}
        old.update(pm_ack_printed_name=test_name, pm_parent_printed_name=test_name,
                   pm_parent_signature="Historical signature", pm_parent_date="2020-01-01",
                   pm_exec_signature="Historical office signature", pm_exec_date="2020-01-02",
                   __pm_scrolledToBottom=True, __pm_scrollTop=999999)
        seed = """async ({secret,data,revision}) => {
          const key=await crypto.subtle.importKey('raw',new TextEncoder().encode(secret.padEnd(32,'0').slice(0,32)),{name:'AES-GCM'},false,['encrypt','decrypt']);
          const iv=crypto.getRandomValues(new Uint8Array(12));
          const sid=localStorage.getItem('graspParentManualSessionId');
          const record={sessionId:sid,updatedAt:new Date().toISOString(),data};
          if(revision!==null) record.manualRevision=revision;
          const bytes=await crypto.subtle.encrypt({name:'AES-GCM',iv},key,new TextEncoder().encode(JSON.stringify(record)));
          const payload={iv:btoa(String.fromCharCode(...iv)),ciphertext:btoa(String.fromCharCode(...new Uint8Array(bytes)))};
          localStorage.setItem('graspParentManualEncryptedData',JSON.stringify(payload));
          const db=await new Promise((resolve,reject)=>{const req=indexedDB.open('graspParentManualDB',1);req.onsuccess=()=>resolve(req.result);req.onerror=reject;});
          await new Promise((resolve,reject)=>{const tx=db.transaction('sessions','readwrite');tx.objectStore('sessions').put({sessionId:sid,encryptedPayload:payload,updatedAt:new Date().toISOString()});tx.oncomplete=resolve;tx.onerror=reject;});
          db.close();return payload;
        }"""
        # Wait for the application's initial save to finish before replacing it.
        page.wait_for_function("localStorage.getItem('graspParentManualEncryptedData') !== null")
        for revision in [None, "old-manual-revision"]:
            payload = page.evaluate(seed, {"secret":secret,"data":old,"revision":revision})
            page.reload()
            notice = page.get_by_role("dialog", name="The Parent Manual has changed")
            expect(notice).to_be_visible()
            expect(notice.get_by_role("button", name="OK", exact=True)).to_be_focused()
            if revision is None:
                page.screenshot(path=str(output/"revision-modal-desktop.png"))
                page.set_viewport_size({"width":390,"height":844})
                box=notice.bounding_box()
                assert box["width"]<=358 and box["x"]>=0
                page.screenshot(path=str(output/"revision-modal-mobile.png"))
                page.set_viewport_size({"width":1280,"height":900})
            notice.get_by_role("button", name="OK", exact=True).click()
            expect(notice).to_have_count(0)
            expect(page.locator("#pm_ack_printed_name")).to_have_value(test_name)
            expect(page.locator("#pm_parent_printed_name")).to_have_value(test_name)
            expect(page.locator("#pm_parent_signature")).to_have_value("")
            expect(page.locator("#pm_parent_date")).to_have_value("")
            expect(page.locator("#pm_exec_signature")).to_have_value("")
            expect(page.locator("#pm_exec_date")).to_have_value("")
            assert all(page.locator("#"+f["name"]).input_value()=="" for f in initials)
            assert page.evaluate("window.formState.__pm_scrolledToBottom !== true")
            archive = page.evaluate("revision => {const sid=localStorage.getItem('graspParentManualSessionId');return JSON.parse(localStorage.getItem('graspParentManualArchivedDraft:'+sid+':'+encodeURIComponent(revision||'unversioned')));}", revision)
            assert archive["encryptedPayload"]==payload
            page.wait_for_function("""async ({secret, revision}) => {
              const payload=JSON.parse(localStorage.getItem('graspParentManualEncryptedData'));
              const key=await crypto.subtle.importKey('raw',new TextEncoder().encode(secret.padEnd(32,'0').slice(0,32)),{name:'AES-GCM'},false,['decrypt']);
              const iv=Uint8Array.from(atob(payload.iv),c=>c.charCodeAt(0));
              const bytes=Uint8Array.from(atob(payload.ciphertext),c=>c.charCodeAt(0));
              const plain=await crypto.subtle.decrypt({name:'AES-GCM',iv},key,bytes);
              return JSON.parse(new TextDecoder().decode(plain)).manualRevision===revision;
            }""", arg={"secret":secret,"revision":cfg["manual"]["revision"]})
        passed("legacy and changed-revision drafts: encrypted archives, names retained, acknowledgements cleared")
        page.reload()
        expect(page.locator("#pm_parent_date")).to_have_value("")
        expect(page.locator("#pm-revision-notice")).to_have_count(0)
        passed("modal dismissal survives reload; fresh-date requirement remains")
        for f in initials:
            page.locator('[data-initials-for="'+f["name"]+'"]').click()
            field = page.locator("#"+f["name"])
            field.fill("q1a")
            field.press("Tab")
            expect(field).to_have_value("QA")
            expect(page.locator('[data-initials-for="'+f["name"]+'"]')).to_have_text("QA")
        page.locator("#pm_parent_signature").fill(test_name)
        page.locator("#pm_parent_date").fill("2026-10-08")
        page.locator("#pm-btn-save").click()
        page.wait_for_timeout(1000)
        page.reload()
        assert all(page.locator("#"+f["name"]).input_value()=="QA" for f in initials)
        expect(page.locator("#pm_parent_signature")).to_have_value(test_name)
        expect(page.locator("#pm_parent_date")).to_have_value("2026-10-08")
        page.locator("#pm-btn-preview").click()
        expect(page.locator("#grasp-preview-submit")).to_be_disabled()
        page.locator("#grasp-preview-cancel").click()
        passed("editing normalization, same-revision restore, scrolling still required")
        page.locator("#pm-scroll").evaluate("el => { el.scrollTop=el.scrollHeight; el.dispatchEvent(new Event('scroll')); }")
        page.wait_for_function("window.formState.__pm_scrolledToBottom === true")
        page.locator("#pm-btn-save").click()
        page.wait_for_timeout(1000)
        page.reload()
        page.locator("#pm-btn-preview").click()
        expect(page.locator("#grasp-preview-submit")).to_be_enabled()
        expect(page.locator('img[alt^="Preview page"]')).to_have_count(33)
        expect(page.locator(".pm-preview-overlay .pm-print-initials")).to_have_count(16)
        assert page.locator(".pm-preview-overlay .pm-print-initials").all_text_contents()==["QA"]*16
        page.screenshot(path=str(output/"preview.png"))
        passed("completed preview and scroll restoration")
        page.locator("#grasp-preview-print").click()
        page.wait_for_function("[...document.querySelectorAll('iframe')].some(f => f.contentWindow.__testPrintInvoked)")
        frame = next(f for f in page.frames if f != page.main_frame and f.evaluate("!!window.__testPrintInvoked"))
        assert frame.locator("img").count()==33
        assert frame.locator(".pm-print-initials").all_text_contents()==["QA"]*16
        assert "parent-manual-print.css" in frame.content()
        (output/"browser-print.html").write_text(frame.content())
        passed("print frame has 33 pages and initials; physical printing suppressed")
        with page.expect_response(lambda response: response.url.endswith('/api/submit_parent_manual.php'),timeout=120000) as response:
            page.locator("#grasp-preview-submit").click()
        result=response.value.json()
        assert response.value.ok and result.get("ok") is True, result
        passed("actual local submission endpoint succeeds")
        message=None
        for _ in range(30):
            messages=api.get(MAILPIT+"/api/v1/messages").json().get("messages",[])
            message=next((m for m in messages if test_name in m.get("Subject","")),None)
            if message:break
            time.sleep(.5)
        assert message, "Test mail not captured"
        raw=api.get(MAILPIT+"/api/v1/message/"+message["ID"]+"/raw")
        assert raw.ok
        mail=email.message_from_bytes(raw.body())
        attachments=[part for part in mail.walk() if part.get_content_type()=="application/pdf"]
        assert len(attachments)==1
        pdf=output/"completed-manual.pdf";pdf.write_bytes(attachments[0].get_payload(decode=True))
        info=subprocess.run(["pdfinfo",str(pdf)],capture_output=True,text=True,check=True).stdout
        assert re.search(r"Pages:\s+33\b",info)
        text=subprocess.run(["pdftotext",str(pdf),"-"],capture_output=True,text=True,check=True).stdout
        assert len(re.findall(r"\bQA\b",text))==16 and test_name in text
        passed("Mailpit captured email with real completed 33-page PDF and 16 initials")
        (output/"results.json").write_text(json.dumps({"passed":results,"testName":test_name,"mailpitMessageId":message["ID"],"manualRevision":cfg["manual"]["revision"],"browserErrors":browser_errors},indent=2))
        assert not browser_errors, browser_errors
        browser.close()
    print("Evidence:", output)


if __name__ == "__main__":
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output",type=Path,default=Path("/tmp")/("grasp-parent-manual-test-"+time.strftime("%Y%m%d-%H%M%S")))
    args=parser.parse_args()
    run(args.output.resolve())
