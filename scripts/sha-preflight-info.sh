#!/bin/bash
echo "SHA-1 of current commit:"
echo "the commands - "
echo "git rev-parse HEAD"
echo "git status --porcelain"
echo "sha1sum of api/lib/EmailPrintTemplate.php - "
echo "return :"
git rev-parse HEAD
git status --porcelain
sha1sum api/lib/EmailPrintTemplate.php