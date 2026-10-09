#!/usr/bin/env python3
"""DDEV-only zoom regression; isolated headless browser, no emails or user drafts.
Run from Ubuntu-D: python3 scripts/test_parent_manual_zoom.py
"""
import json
import re
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright, expect

BASE="https://reg-form-project.ddev.site"
OUTPUT=Path("/tmp/grasp-parent-manual-zoom-test")
OUTPUT.mkdir(parents=True,exist_ok=True)
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path="/usr/bin/google-chrome",headless=True,args=["--no-sandbox"])
    context=browser.new_context(ignore_https_errors=True,viewport={"width":1280,"height":900})
    context.route("**/*",lambda route: route.continue_() if urlsplit(route.request.url).hostname=="reg-form-project.ddev.site" else route.abort())
    page=context.new_page();errors=[];page.on("pageerror",lambda e:errors.append(str(e)))
    page.goto(BASE+"/parent-manual-form/")
    expect(page.locator(".pm-page")).to_have_count(33)
    page.wait_for_function("window.__pmZoom===1")
    image=page.locator(".pm-page img").first
    def width():return image.bounding_box()["width"]
    base=width();measurements=[base]
    for factor in [1.1,1.2,1.3]:
        page.locator("#pm-zoom-in").click()
        expect(page.locator("#pm-zoom-value")).to_have_text(str(round(factor*100))+"%")
        actual=width();assert abs(actual/base-factor)<.005,(actual,base,factor)
        measurements.append(actual)
    page.locator("#pm-zoom-out").click();assert abs(width()/base-1.2)<.005
    print("PASS: repeated + grows the actual page/text; - shrinks it",measurements)
    page.locator("#pm-zoom-reset").click();assert abs(width()-base)<1
    # Read midway into page 9 and retain that same source point when zooming.
    page.locator('img[alt="Parent Manual page 9"]').scroll_into_view_if_needed()
    page.locator("#pm_initials_p09_01").wait_for(state="attached")
    page.wait_for_function("document.querySelector('img[alt=\"Parent Manual page 9\"]').complete")
    point="""() => { const sc=document.querySelector('#pm-scroll'),img=document.querySelector('img[alt="Parent Manual page 9"]');
      const sr=sc.getBoundingClientRect(),ir=img.getBoundingClientRect();
      return (sr.top+sc.clientTop+parseFloat(getComputedStyle(sc).paddingTop)-ir.top)/ir.height; }"""
    before=page.evaluate(point)
    page.locator("#pm-zoom-in").click();assert abs(page.evaluate(point)-before)<.005
    def aligned():
        return page.evaluate("""() => { const field=document.querySelector('[data-initials-for="pm_initials_p09_01"]'),overlay=field.parentElement;
          const f=field.getBoundingClientRect(),o=overlay.getBoundingClientRect();
          const cfg=window.config.steps.flatMap(s=>s.groups.flatMap(g=>g.fields)).find(f=>f.name==='pm_initials_p09_01').placement.rect;
          return Math.abs((f.left-o.left)/o.width-cfg.x)<.002 && Math.abs((f.top-o.top)/o.height-cfg.y)<.002; }""")
    assert aligned()
    for _ in range(10):
        if page.locator("#pm-zoom-in").is_enabled():page.locator("#pm-zoom-in").click()
    expect(page.locator("#pm-zoom-value")).to_have_text("175%")
    expect(page.locator("#pm-zoom-in")).to_be_disabled();assert aligned()
    assert page.locator("#pm-scroll").evaluate("el=>el.scrollWidth>el.clientWidth")
    page.locator('[data-initials-for="pm_initials_p09_01"]').click()
    page.locator("#pm_initials_p09_01").fill("qa");page.locator("#pm_initials_p09_01").press("Tab")
    expect(page.locator('[data-initials-for="pm_initials_p09_01"]')).to_have_text("QA")
    page.screenshot(path=str(OUTPUT/"zoom-175.png"))
    page.reload();page.wait_for_function("window.__pmZoom===1.75");assert abs(width()/base-1.75)<.005
    print("PASS: reading anchor, overlay alignment/editing, horizontal pan, maximum and reload persistence")
    scroller=page.locator("#pm-scroll")
    scroller.scroll_into_view_if_needed()
    def offsets():return scroller.evaluate("el=>({x:el.scrollLeft,y:el.scrollTop})")
    def center():
        box=scroller.bounding_box()
        return box["x"]+box["width"]*.45,box["y"]+box["height"]*.4
    def drag(dx,dy):
        x,y=center();page.mouse.move(x,y);page.mouse.down()
        page.mouse.move(x+dx,y+dy,steps=8);page.mouse.up()
    for dx,dy in [(-70,0),(0,-60),(-70,-60),(50,40)]:
        scroller.evaluate("el=>{el.scrollLeft=160;el.scrollTop=1700}")
        start=offsets();drag(dx,dy);end=offsets()
        assert abs(end["x"]-(start["x"]-dx))<2,(start,end,dx)
        assert abs(end["y"]-(start["y"]-dy))<2,(start,end,dy)
        page.mouse.move(700,400,steps=4);assert offsets()==end
        assert not scroller.evaluate("el=>el.classList.contains('pm-panning')")
    print("PASS: horizontal, vertical, diagonal and reverse drag; immediate stop on release")
    # Captured pointer release outside the viewer must also stop movement.
    scroller.evaluate("el=>{el.scrollLeft=160;el.scrollTop=1700}")
    x,y=center();page.mouse.move(x,y);page.mouse.down();page.mouse.move(100,y,steps=8);page.mouse.up()
    stopped=offsets();page.mouse.move(x,y+40,steps=8);assert offsets()==stopped
    assert not scroller.evaluate("el=>el.classList.contains('pm-panning')")
    # Background drag ending over a field must not open its editor.
    display=page.locator('[data-initials-for="pm_initials_p09_01"]')
    display.scroll_into_view_if_needed();scroller.scroll_into_view_if_needed()
    scroller.evaluate("el=>el.scrollLeft=el.scrollWidth")
    target=display.bounding_box();x=target["x"]+target["width"]/2;y=target["y"]+target["height"]/2
    page.mouse.move(target["x"]+target["width"]+10,y);page.mouse.down();page.mouse.move(x,y,steps=8)
    assert page.evaluate("({x,y})=>document.elementFromPoint(x,y)?.closest('.pm-initials-display')?.dataset.initialsFor==='pm_initials_p09_01'",{"x":x,"y":y})
    page.mouse.up()
    expect(page.locator("#pm_initials_p09_01")).to_have_class(re.compile('pm-initials-hidden'))
    display.click();page.locator("#pm_initials_p09_01").fill("pan")
    page.locator("#pm_initials_p09_01").press("Tab");expect(display).to_have_text("PAN")
    page.locator("#pm-btn-save").click();page.wait_for_timeout(1000);page.reload()
    expect(page.locator("#pm_initials_p09_01")).to_have_value("PAN")
    print("PASS: release outside viewer; drag does not activate initials; click/edit/save/restore remain usable")
    scroller.scroll_into_view_if_needed()
    # Clamp at the document edges rather than moving pages outside the viewport.
    scroller.evaluate("el=>{el.scrollLeft=0;el.scrollTop=0}");drag(60,50)
    assert offsets()=={"x":0,"y":0}
    scroller.evaluate("el=>{el.scrollLeft=el.scrollWidth;el.scrollTop=el.scrollHeight}")
    edge=offsets();drag(-60,-50);assert offsets()==edge
    # A zoom change during drag must release capture and end panning.
    scroller.evaluate("el=>{el.scrollLeft=160;el.scrollTop=1700}")
    x,y=center();page.mouse.move(x,y);page.mouse.down();page.mouse.move(x-20,y-20,steps=4)
    page.keyboard.press("Control+-")
    assert not scroller.evaluate("el=>el.classList.contains('pm-panning')")
    stopped=offsets();page.mouse.move(x-60,y-60,steps=4);page.mouse.up();assert offsets()==stopped
    print("PASS: document boundaries and zoom changes end dragging safely")
    # Browser cancellation, capture loss and window blur must stop a held drag.
    for action in ["cancel", "capture-loss", "blur"]:
        scroller.evaluate("el=>{el.scrollLeft=160;el.scrollTop=1700;el.addEventListener('gotpointercapture',e=>window.__testPanPointer=e.pointerId,{once:true});}")
        x,y=center();page.mouse.move(x,y);page.mouse.down();page.mouse.move(x-20,y-20,steps=4)
        assert scroller.evaluate("el=>el.classList.contains('pm-panning')")
        pointer=page.evaluate("window.__testPanPointer")
        if action=="cancel":scroller.dispatch_event("pointercancel",{"pointerId":pointer,"pointerType":"mouse","bubbles":True})
        elif action=="capture-loss":scroller.evaluate("(el,id)=>el.releasePointerCapture(id)",pointer)
        else:page.evaluate("window.dispatchEvent(new Event('blur'))")
        stopped=offsets();page.mouse.move(x-70,y-70,steps=4);page.mouse.up()
        assert offsets()==stopped,(action,stopped,offsets())
        assert not scroller.evaluate("el=>el.classList.contains('pm-panning')")
    print("PASS: pointer cancellation, capture loss and window blur stop movement")

    for _ in range(12):
        if page.locator("#pm-zoom-out").is_enabled():page.locator("#pm-zoom-out").click()
    expect(page.locator("#pm-zoom-value")).to_have_text("75%")
    expect(page.locator("#pm-zoom-out")).to_be_disabled();assert abs(width()/base-.75)<.005
    scroller.scroll_into_view_if_needed();scroller.evaluate("el=>el.scrollTop=1700")
    start=offsets();drag(-40,-40);assert offsets()==start
    assert not scroller.evaluate("el=>el.classList.contains('pm-pan-enabled')")
    page.locator("#pm-zoom-reset").click();expect(page.locator("#pm-zoom-value")).to_have_text("100%")
    assert abs(width()-base)<1
    scroller.scroll_into_view_if_needed();start=offsets();drag(-40,-40);assert offsets()==start
    assert not scroller.evaluate("el=>el.classList.contains('pm-pan-enabled')")
    print("PASS: drag disabled at 75% and 100%")
    page.set_viewport_size({"width":390,"height":844});page.wait_for_timeout(100)
    resized=width();page.locator("#pm-zoom-in").click();assert abs(width()/resized-1.1)<.005
    assert aligned();print("PASS: minimum, Reset and responsive resizing")
    assert not errors,errors
    (OUTPUT/"results.json").write_text(json.dumps({"pageWidths100to130":measurements,"browserErrors":errors,"passed":True,"dragPanChecks":["all directions","release inside/outside","initials edit and restore","document boundaries","zoom during drag","disabled at 75/100%"]},indent=2))
    browser.close()
