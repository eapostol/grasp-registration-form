#!/bin/bash

# Script to find and delete .identifier files in the project directory

echo "Scanning for .identifier files..."
echo ""

# Find all .identifier files
identifier_files=$(find . -type f -name "*.identifier" 2>/dev/null)

if [ -z "$identifier_files" ]; then
    echo "No .identifier files found."
    exit 0
fi

# Display found files
echo "Found the following .identifier files:"
echo ""
echo "$identifier_files"
echo ""

# Prompt user for confirmation
read -p "Do you want to delete these files? (yes/no): " response

case "$response" in
    [yY][eE][sS]|[yY])
        echo "$identifier_files" | while read -r file; do
            rm -f "$file"
            echo "Deleted: $file"
        done
        echo ""
        echo "Deletion complete."
        ;;
    [nN][oO]|[nN])
        echo "Deletion cancelled."
        ;;
    *)
        echo "Invalid response. Please enter 'yes' or 'no'."
        exit 1
        ;;
esac