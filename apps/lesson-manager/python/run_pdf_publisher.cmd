@echo off
setlocal
set "SCRIPT_DIR=%~dp0"
set "PYTHON_EXE="
if defined LESSON_PUBLISHER_PYTHON if exist "%LESSON_PUBLISHER_PYTHON%" set "PYTHON_EXE=%LESSON_PUBLISHER_PYTHON%"
if not defined PYTHON_EXE if exist "%SCRIPT_DIR%.venv\Scripts\python.exe" set "PYTHON_EXE=%SCRIPT_DIR%.venv\Scripts\python.exe"
if not defined PYTHON_EXE if exist "C:\projects\education_scraper\.venv\Scripts\python.exe" set "PYTHON_EXE=C:\projects\education_scraper\.venv\Scripts\python.exe"
if defined PYTHON_EXE (
  "%PYTHON_EXE%" "%SCRIPT_DIR%pdf_lesson_publisher.py" %*
  exit /b %ERRORLEVEL%
)
where py.exe >nul 2>&1
if not errorlevel 1 (
  py.exe -3 "%SCRIPT_DIR%pdf_lesson_publisher.py" %*
  exit /b %ERRORLEVEL%
)
echo No working Python interpreter was found. Set LESSON_PUBLISHER_PYTHON first. 1>&2
exit /b 1
