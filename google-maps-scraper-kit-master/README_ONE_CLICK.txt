GOOGLE MAPS SCRAPER - ONE CLICK WINDOWS LAUNCHER
================================================

Requirements
------------
- Windows 10/11
- Docker Desktop installed and running
- Internet connection

Start
-----
1. Extract this folder anywhere.
2. Start Docker Desktop.
3. Double-click START_ONE_CLICK.vbs.
4. The local Google Maps Scraper web interface will open automatically in your browser.

The web interface is local to this computer:
http://127.0.0.1:8080

No PowerShell window or command prompt is required for normal use.

If the browser does not open
----------------------------
Double-click OPEN_APP.bat.

If the scraper is not ready
---------------------------
The first launch may take longer because Docker may need to download the scraper image.
Wait a minute and run START_ONE_CLICK.vbs again.

Stop
----
Double-click STOP_SCRAPER.bat.

Security / privacy
------------------
- The scraper API is bound to 127.0.0.1:8080 (localhost only).
- This launcher does not install software.
- Docker Desktop is required and is not bundled.
- The scraper itself accesses Google Maps and other web resources as part of its intended operation.
- Review the included README.md, SETUP.md and CREDITS.md for the underlying project's details.

Underlying scraper
------------------
This package uses gosom/google-maps-scraper through Docker, pinned in docker-compose.yml.
