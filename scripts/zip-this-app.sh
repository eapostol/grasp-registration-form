#!/bin/bash

# Generate timestamp for the zip file
TIMESTAMP=$(date +%Y-%m-%d-%I%M%p)
ZIP_FILE="../grasp-registration-form-${TIMESTAMP}.zip"

# Create zip archive excluding non-essential files and folders
zip -r "$ZIP_FILE" . \
    -x ".git/*" ".git/**" \
    "*.log" \
    "*Zone.Identifier*" \
    "node_modules/*" "node_modules/**" \
    ".ddev/.db/*" ".ddev/.db/**" \
    "**/*.zip" \
    ".vscode/*" ".vscode/**" \
    ".next/*" ".next/**" \
    "dist/*" "dist/**" \
    "build/*" "build/**" \
    ".env" ".env.local" \
    "*.swp" "*.swo" "*~" \
    ".DS_Store" \
    "Thumbs.db"

echo "✓ Project archived as: $ZIP_FILE"