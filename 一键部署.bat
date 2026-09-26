@echo off
rem chcp 936 (GBK encoded file)
setlocal EnableDelayedExpansion

:: ================================================
::  缘分故事屋 · 一键打包部署
::  通道一：阿里云 Workbench CLI（免密，推荐）
::  通道二：Windows 内置 ssh/scp（需输密码）
:: ================================================

:: ---------- 配置区（按需修改） ----------
set INSTANCE_ID=i-7xvel996yq2tycc1h48v
set SERVER_USER=root
set SERVER_IP=8.163.10.206
set REMOTE_DIR=/home/Bhands_StoryBase
set APP_PORT=8010
set REGION=cn-guangzhou
:: --------------------------------------

cd /d %~dp0

echo ================================================
echo   缘分故事屋 · 一键打包部署
echo ================================================

:: ---------- [1/6] 本地打包 ----------
echo [1/6] 本地打包 deploy.zip（自动排除运行时数据/密钥/缓存）...
if exist deploy.zip del deploy.zip >nul 2>nul
tar -a -c -f deploy.zip --exclude ".git" --exclude "node_modules" --exclude ".loomy-attachments" --exclude "*.png" --exclude "*.zip" --exclude "docs" --exclude "data/stories.json" --exclude "data/feedback.json" --exclude "data/views_log.json" --exclude "package-lock.json" --exclude ".runtime-*" --exclude ".tmp-*" .
if errorlevel 1 (echo [X] 打包失败 & pause & exit /b 1)
if not exist deploy.zip (echo [X] 打包产物缺失 & pause & exit /b 1)
echo       OK：deploy.zip 已生成（不含 stories.json 等服务器真实数据）

:: ---------- [2/6] 探测部署通道 ----------
set CHANNEL=
set WB=
where workbench >nul 2>nul
if !errorlevel! EQU 0 set WB=workbench
if not defined WB (if exist "C:\Users\Public\workbench\workbench.exe" set "WB=C:\Users\Public\workbench\workbench.exe")
if not defined WB (if exist "%LOCALAPPDATA%\workbench\workbench.exe" set "WB=%LOCALAPPDATA%\workbench\workbench.exe")
if not defined WB (
    echo       [i] workbench probe failed - check probe.txt for details
) else (
    "!WB!" list -o json --region !REGION! > probe.txt 2>&1
    if !errorlevel! EQU 0 (
        findstr /c:"i-" probe.txt >nul
        if !errorlevel! EQU 0 set CHANNEL=workbench
    ) else (
        echo       [i] workbench probe failed - see probe.txt:
        type probe.txt
    )
)
if not defined CHANNEL (
    where ssh >nul 2>nul
    if !errorlevel! EQU 0 set CHANNEL=ssh
)
if not defined CHANNEL (
    echo [X] 未找到 workbench 或 ssh 命令，无法部署
    pause
    exit /b 1
)
echo [2/6] 部署通道：!CHANNEL!

if "!CHANNEL!"=="workbench" goto deploy_by_workbench
goto deploy_by_ssh

:: ---------- Workbench 免密通道 ----------
:deploy_by_workbench
if "!INSTANCE_ID!"=="" (
    echo [X] 配置区 INSTANCE_ID 为空。请先执行一次获取实例 ID：
    echo       workbench list
    echo       然后把 i-xxxx 填到本脚本顶部 INSTANCE_ID= 后面。
    echo       （若尚未配置登录，请先执行：workbench config）
    pause
    exit /b 1
)
echo [3/6] 上传 deploy.zip（经阿里云 OSS 中转，免密）...
"!WB!" upload deploy.zip !REMOTE_DIR!/deploy.zip --region !REGION! --instance-id !INSTANCE_ID! --force
if errorlevel 1 (echo [X] 上传失败 & pause & exit /b 1)
echo [4/6] 远程解压并重建容器（2核2G 构建约 1-2 分钟，耐心等待）...
"!WB!" exec --region !REGION! --instance-id !INSTANCE_ID! --timeout 300 --command "cd !REMOTE_DIR! && (which unzip >/dev/null 2>&1 || apt-get install -y unzip) && unzip -o deploy.zip -d . && grep -q 'docker-entrypoint.sh' Dockerfile && echo '[server] Dockerfile version OK' && docker compose up -d --build"
if errorlevel 1 (echo [X] 远程构建失败，请登录服务器手动检查 & pause & exit /b 1)
echo [5/6] 验证部署...
"!WB!" exec --region !REGION! --instance-id !INSTANCE_ID! --timeout 60 --command "docker exec yuanfen-stories ls /var/www/html/ && sleep 2 && echo '--- API test ---' && curl -s http://127.0.0.1:!APP_PORT!/api.php?route=story&id=5&count=0 | head -c 150"
echo.
goto done

:: ---------- ssh/scp 密码通道 ----------
:deploy_by_ssh
echo [3/6] 上传 deploy.zip（需要输入服务器密码，可能多次）...
scp -o StrictHostKeyChecking=accept-new deploy.zip !SERVER_USER!@!SERVER_IP!:!REMOTE_DIR!/deploy.zip
if errorlevel 1 (echo [X] 上传失败，请检查网络/密码 & pause & exit /b 1)
echo [4/6] 远程解压并重建容器...
ssh -o StrictHostKeyChecking=accept-new !SERVER_USER!@!SERVER_IP! "cd !REMOTE_DIR! && (which unzip >/dev/null 2>&1 || apt-get install -y unzip) && unzip -o deploy.zip -d . && grep -q 'docker-entrypoint.sh' Dockerfile && echo '[server] Dockerfile version OK' && docker compose up -d --build"
if errorlevel 1 (echo [X] 远程构建失败，请登录服务器手动检查 & pause & exit /b 1)
echo [5/6] 验证部署...
ssh !SERVER_USER!@!SERVER_IP! "docker exec yuanfen-stories ls /var/www/html/ && sleep 2 && echo '--- API test ---' && curl -s http://127.0.0.1:!APP_PORT!/api.php?route=story&id=5&count=0 | head -c 150"
echo.
goto done

:: ---------- 完成 ----------
:done
del probe.txt >nul 2>nul
echo [6/6] 部署完成！
echo       打开 http://!SERVER_IP!:!APP_PORT! 检查站点（或 bhands.me）
echo       提示：SSH 密码模式每次要输多次密码，想免密可配置 Workbench：
echo       1) workbench config   2) workbench list   3) 把实例 ID 填进本脚本
pause
