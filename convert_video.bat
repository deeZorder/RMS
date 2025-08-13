@echo off
echo Converting Magical Rainforest.mp4 from AV1 to H.264 for Android TV compatibility...
echo.

REM Check if FFmpeg exists
if not exist "c:\ffmpeg\bin\ffmpeg.exe" (
    echo ERROR: FFmpeg not found at c:\ffmpeg\bin\ffmpeg.exe
    echo Please install FFmpeg or update the path in this script
    pause
    exit /b 1
)

REM Convert AV1 to H.264
echo Starting conversion...
echo Input: videos/Magical Rainforest.mp4 (AV1, 1920x1080, 25fps)
echo Output: videos/Magical Rainforest_H264.mp4 (H.264, 1920x1080, 25fps)
echo.

c:\ffmpeg\bin\ffmpeg.exe -i "videos/Magical Rainforest.mp4" -c:v libx264 -c:a aac -preset medium -crf 23 -y "videos/Magical Rainforest_H264.mp4"

if %errorlevel% equ 0 (
    echo.
    echo SUCCESS: Video converted successfully!
    echo.
    echo New file: videos/Magical Rainforest_H264.mp4
    echo Format: H.264/AVC (Android TV compatible)
    echo Resolution: 1920x1080
    echo Frame Rate: 25fps
    echo.
    echo Next steps:
    echo 1. Update your dashboard to use the new H.264 file
    echo 2. Test on your Android TV
    echo 3. The black screen issue should be resolved!
) else (
    echo.
    echo ERROR: Conversion failed!
    echo Check the error messages above
)

echo.
pause
