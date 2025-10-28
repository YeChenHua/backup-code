@echo off
:: ====== GitHub 自動備份批次檔 (繁體中文版修正版) ======
:: 將以下路徑改成你的專案資料夾
cd /d "C:\xampp\htdocs\PYTHON"

:: 檢查 Git 狀態
git status

:: 新增全部修改
git add .

:: 自動建立 commit，使用安全格式的時間戳
setlocal enabledelayedexpansion
for /f "tokens=1-3 delims=/- " %%a in ('date /t') do (
    set mydate=%%a-%%b-%%c
)
for /f "tokens=1-2 delims=: " %%a in ('time /t') do (
    set mytime=%%a%%b
)
git commit -m "Auto backup on %mydate%_%mytime%"

:: 上傳到 GitHub
git push

:: （可選）上傳後自動開啟 GitHub 頁面
start https://github.com/YeChenHua/backup-code


echo.
echo  專案已成功備份到 GitHub！
pause
