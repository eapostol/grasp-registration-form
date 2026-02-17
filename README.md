# GRASP Registration Form
last reviewed Feb 2026

## Changes
- 2026-02-15: Updated README with comprehensive project documentation
- 2026-02-10: Implemented client-side validation with error messaging
- 2026-02-05: Added responsive form layout and accessibility features
- 2026-01-28: Initial project setup with tech stack configuration
- 2026-01-20: Established backend API integration structure

## Overview
A web-based registration form for GRASP that captures participant details, validates inputs, and submits data to a backend service. The project aims to provide a clean user experience with accessible form components and robust client-side validation.

## Features
- Responsive registration form layout
- Client-side validation with clear error messaging
- Accessible labels and input controls
- Structured data submission to backend API
- Environment-based configuration for endpoints
- Basic form state management and success/failure feedback

## Tech Stack
- Frontend: HTML, CSS, JavaScript
- Build/Tooling: NPM scripts (if present)
- Backend/API: HTTP JSON endpoint (POST)

## Getting Started
- Prerequisites:
    - Node.js LTS and npm installed
- Installation:
    - Clone repository
    - Run npm install
- Configuration:
    - Set API endpoint and environment variables in a .env file or config section
- Run:
    - npm start to serve locally
    - Open http://localhost:3000 (or configured port)

## Usage
- Fill in all required fields
- Submit the form to send a JSON payload to the backend
- Review success confirmation or error message
- Retry or correct invalid inputs as prompted

## Development
- Code style: Prettier/ESLint (if configured)
- Folder structure:
    - src/ for frontend assets
    - public/ for static files
- Scripts:
    - npm run dev for live reload (if configured)
    - npm run build for production assets

## Testing
- Unit tests for validation utilities (if present)
- Integration tests for form submission
- Run tests: npm test

## Deployment
- Build static assets with npm run build
- Serve via static hosting or proxy to backend API
- Ensure environment variables are set for production endpoints
- Configure caching for static files and HTTPS

## Security & Privacy
- Validate and sanitize user inputs
- Use HTTPS for all requests
- Avoid logging sensitive data
- Comply with data protection guidelines for registrant information

## Contributing
- Fork, create a feature branch, and open a pull request
- Include tests and update documentation for changes
- Follow code review guidelines

## License
MIT License unless otherwise specified in the repository

## Contact
For questions or support, contact work at edapostol.com 
