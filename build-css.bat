@echo off
set TAILWIND=%~dp0tools\tailwindcss.exe
%TAILWIND% -i %~dp0resources\css\app.css -o %~dp0public\assets\css\app.css --config %~dp0tailwind.config.js --minify
