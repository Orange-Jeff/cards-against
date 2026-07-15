@echo off
:: Version 1.0 - Against Game Audio Manager
:menu
cls
echo ===================================================
echo               AGAINST GAME AUDIO MANAGER           
echo ===================================================
echo.

:: Count segments
set segments_count=0
for /f %%A in ('dir /b /s "audio\segments\*.mp3" 2^>nul ^| find /c /v ""') do set segments_count=%%A

:: Count whites
set whites_count=0
for /f %%A in ('dir /b /s "audio\whites\*.mp3" 2^>nul ^| find /c /v ""') do set whites_count=%%A

:: Count host messages
set host_count=0
for /f %%A in ('dir /b /s "audio\host_messages\*.mp3" 2^>nul ^| find /c /v ""') do set host_count=%%A

set /a total_count=%segments_count% + %whites_count% + %host_count%

echo [Current Status]
echo   - Black Card Segments: %segments_count% files
echo   - White Card Audio:    %whites_count% files
echo   - Host Comments:       %host_count% files
echo   - Total Generated:     %total_count% files
echo.
echo ===================================================
echo   1. Start/Resume Host Messages Audio Generation
echo   2. Start/Resume Deck Cards Audio Generation
echo   3. Refresh Status
echo   4. Exit
echo ===================================================
echo.

set /p choice="Select an option (1-4): "

if "%choice%"=="1" goto run_host
if "%choice%"=="2" goto run_deck
if "%choice%"=="3" goto menu
if "%choice%"=="4" goto exit

echo Invalid choice. Try again.
pause
goto menu

:run_host
echo.
echo Starting/Resuming Host Messages Audio Generation...
echo Press Ctrl+C at any time to pause/stop.
echo.
"e:\php\php.exe" generate_host_audio.php
echo.
echo Process complete or paused.
pause
goto menu

:run_deck
echo.
echo Starting/Resuming Deck Cards Audio Generation...
echo Press Ctrl+C at any time to pause/stop.
echo.
"e:\php\php.exe" run_audio_generation.php
echo.
echo Process complete or paused.
pause
goto menu

:exit
echo Goodbye!
exit /b
