#!/bin/bash
echo "SHA-1 of current commit:"
echo "the commands - "
echo "git rev-parse HEAD"
echo "git status --porcelain"
echo "sha1sum of api/lib/EmailPrintTemplate.php - "
echo "returns :"
echo "the results of git rev-parse HEAD: $(git rev-parse HEAD)"

if [ -n "$(git status --porcelain)" ]; then
    echo "git status --porcelain returns: $(git status --porcelain)"
else
    echo "git status --porcelain returns nothing"
fi

echo "the value returned from sha1sum api/lib/EmailPrintTemplate.php is : $(sha1sum api/lib/EmailPrintTemplate.php)"