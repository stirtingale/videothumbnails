<?php

/**
 * MP4 Video Thumbnail Generator
 * 
 * This script allows users to:
 * - Upload an MP4 video directly from their browser
 * - Select a range (start and end timecodes) for the clip
 * - Preview the video with interactive timecode selection
 * - Set custom width or height (with auto-calculation of the other dimension)
 * - Choose output quality
 * - Export the clip as an MP4 optimized for web
 */

// Set error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Cleanup old files
function cleanupOldFiles()
{
    // Configuration
    $cleanupAge = 10 * 60; // 10 minutes in seconds
    $directories = [
        __DIR__ . '/uploads',
        __DIR__ . '/clips',
        __DIR__ . '/thumbnails'
    ];
    $maxFilesToProcess = 20; // Limit the number of files to check per request

    // Current time
    $now = time();

    // Track files processed and deleted
    $processed = 0;
    $deleted = 0;

    // Process each directory
    foreach ($directories as $directory) {
        if (!file_exists($directory)) {
            continue;
        }

        // Get a list of files in the directory
        $files = glob($directory . '/*');

        // Shuffle files to avoid always checking the same ones first
        shuffle($files);

        foreach ($files as $file) {
            // Stop if we've reached the maximum number of files to process
            if ($processed >= $maxFilesToProcess) {
                break 2; // Break out of both loops
            }

            // Skip directories
            if (is_dir($file)) {
                continue;
            }

            $processed++;

            // Get file modification time
            $fileModTime = filemtime($file);
            $fileAge = $now - $fileModTime;

            // If file is older than the cleanup age, delete it
            if ($fileAge > $cleanupAge) {
                try {
                    if (unlink($file)) {
                        $deleted++;
                    }
                } catch (Exception $e) {
                    // Silently continue on error
                }
            }
        }
    }

    return [
        'processed' => $processed,
        'deleted' => $deleted
    ];
}

// Run cleanup at the beginning
$cleanupResult = cleanupOldFiles();

// Function to generate video clip from the selected range
function generateClip($videoPath, $startTime, $endTime, $width, $height, $quality, $outputPath)
{
    // Ensure FFmpeg is installed
    exec('ffmpeg -version', $output, $returnVar);
    if ($returnVar !== 0) {
        return [
            'success' => false,
            'message' => 'FFmpeg is not installed or accessible'
        ];
    }

    // Convert timecodes to seconds if in format HH:MM:SS
    if (preg_match('/^(\d+):(\d+):(\d+)(?:\.(\d+))?$/', $startTime, $matches)) {
        $startSeconds = $matches[1] * 3600 + $matches[2] * 60 + $matches[3];
        if (isset($matches[4])) {
            $startSeconds += floatval("0.{$matches[4]}");
        }
    } elseif (preg_match('/^(\d+):(\d+)(?:\.(\d+))?$/', $startTime, $matches)) {
        $startSeconds = $matches[1] * 60 + $matches[2];
        if (isset($matches[3])) {
            $startSeconds += floatval("0.{$matches[3]}");
        }
    } else {
        $startSeconds = (float)$startTime;
    }

    if (preg_match('/^(\d+):(\d+):(\d+)(?:\.(\d+))?$/', $endTime, $matches)) {
        $endSeconds = $matches[1] * 3600 + $matches[2] * 60 + $matches[3];
        if (isset($matches[4])) {
            $endSeconds += floatval("0.{$matches[4]}");
        }
    } elseif (preg_match('/^(\d+):(\d+)(?:\.(\d+))?$/', $endTime, $matches)) {
        $endSeconds = $matches[1] * 60 + $matches[2];
        if (isset($matches[3])) {
            $endSeconds += floatval("0.{$matches[3]}");
        }
    } else {
        $endSeconds = (float)$endTime;
    }

    // Calculate duration
    $duration = $endSeconds - $startSeconds;
    if ($duration <= 0) {
        return [
            'success' => false,
            'message' => 'End time must be greater than start time'
        ];
    }

    // Build dimension parameter based on which one was provided
    $dimensionParam = '';
    if ($width && $height) {
        $dimensionParam = "-s {$width}x{$height}";
    } elseif ($width) {
        $dimensionParam = "-vf scale={$width}:-2";
    } elseif ($height) {
        $dimensionParam = "-vf scale=-2:{$height}";
    }

    // Define quality presets with more options
    $qualityPresets = [
        'lowest' => ['-crf 32', '-preset ultrafast', 0.2],
        'low' => ['-crf 28', '-preset faster', 0.4],
        'medium' => ['-crf 23', '-preset medium', 1.0],
        'high' => ['-crf 18', '-preset slow', 1.5],
        'highest' => ['-crf 14', '-preset veryslow', 2.5]
    ];

    // Set default quality to medium if not specified
    if (!isset($qualityPresets[$quality])) {
        $quality = 'medium';
    }

    list($crfParam, $presetParam, $sizeFactor) = $qualityPresets[$quality];

    // Use FFmpeg to extract the clip with the chosen settings
    // Note: -an strips audio track completely
    $cmd = "ffmpeg -y -ss $startSeconds -i \"$videoPath\" -t $duration $dimensionParam $crfParam $presetParam -c:v libx264 -profile:v main -level 3.1 -pix_fmt yuv420p -movflags +faststart -an \"$outputPath\" 2>&1";
    exec($cmd, $output, $returnVar);

    if ($returnVar !== 0) {
        return [
            'success' => false,
            'message' => 'Failed to generate clip: ' . implode("\n", $output)
        ];
    }

    return [
        'success' => true,
        'path' => $outputPath,
        'start' => $startSeconds,
        'end' => $endSeconds,
        'duration' => $duration
    ];
}

// Format seconds to HH:MM:SS
function formatTime($seconds)
{
    $seconds = max(0, $seconds);
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = floor($seconds % 60);

    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

// Parse time input (supports HH:MM:SS, MM:SS, or seconds)
function parseTimeInput($timeStr)
{
    if (!$timeStr) return 0;

    // Format: HH:MM:SS or HH:MM:SS.mmm
    if (preg_match('/^\d+:\d+:\d+(\.\d+)?$/', $timeStr)) {
        $parts = explode(':', $timeStr);
        return $parts[0] * 3600 + $parts[1] * 60 + floatval($parts[2]);
    }

    // Format: MM:SS or MM:SS.mmm
    if (preg_match('/^\d+:\d+(\.\d+)?$/', $timeStr)) {
        $parts = explode(':', $timeStr);
        return $parts[0] * 60 + floatval($parts[1]);
    }

    // Just seconds
    return floatval($timeStr);
}

// Calculate estimated file size based on duration, dimensions, and quality
function calculateEstimatedFileSize($duration, $width, $height, $quality)
{
    global $qualityPresets;

    // Get size multiplier for selected quality
    $sizeMultiplier = $qualityPresets[$quality][2] ?? 1.0;

    // Base bitrate calculation (very approximative)
    // Formula: pixelCount * framerate * compressionFactor * qualityMultiplier
    $pixelCount = $width * $height;
    $framerate = 30; // Assuming 30fps output
    $compressionFactor = 0.005; // Increased by 10x from previous value (0.0005)

    $bitrate = $pixelCount * $framerate * $compressionFactor * $sizeMultiplier;

    // Calculate size in bytes: bitrate (bits/s) * duration (s) / 8 bits per byte
    $sizeInBytes = ($bitrate * $duration) / 8;

    // Convert to appropriate unit
    if ($sizeInBytes < 1024 * 1024) {
        return round($sizeInBytes / 1024, 1) . ' KB';
    } else {
        return round($sizeInBytes / (1024 * 1024), 1) . ' MB';
    }
}

// Maximum upload size in bytes (for display purposes)
$maxUploadSize = min(
    convertPHPSizeToBytes(ini_get('upload_max_filesize')),
    convertPHPSizeToBytes(ini_get('post_max_size'))
);

function convertPHPSizeToBytes($sizeStr)
{
    $suffix = strtolower($sizeStr[strlen($sizeStr) - 1]);
    $value = (int)$sizeStr;

    switch ($suffix) {
        case 'g':
            $value *= 1024;
        case 'm':
            $value *= 1024;
        case 'k':
            $value *= 1024;
    }

    return $value;
}

// Handle form submission
$result = null;
$videoInfo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startTime = $_POST['start_time'] ?? '0';
    $endTime = $_POST['end_time'] ?? '0';
    $width = !empty($_POST['width']) ? (int)$_POST['width'] : null;
    $height = !empty($_POST['height']) ? (int)$_POST['height'] : null;
    $quality = $_POST['quality'] ?? 'medium';
    $keepVideo = isset($_POST['keep_video']) ? $_POST['keep_video'] : '';

    // Check if we're using a previously uploaded video
    if (!empty($keepVideo) && file_exists($keepVideo)) {
        $videoPath = $keepVideo;
        $fileName = basename($videoPath);

        // Get video information
        $ffprobeCmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"$videoPath\"";
        exec($ffprobeCmd, $durationOutput, $durationReturnVar);

        if ($durationReturnVar === 0 && !empty($durationOutput[0])) {
            $duration = (float)$durationOutput[0];

            // Get video dimensions
            $dimensionsCmd = "ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 \"$videoPath\"";
            exec($dimensionsCmd, $dimensionsOutput, $dimensionsReturnVar);

            if ($dimensionsReturnVar === 0 && !empty($dimensionsOutput[0])) {
                list($videoWidth, $videoHeight) = explode('x', $dimensionsOutput[0]);

                // Get video bitrate
                $bitrateCmd = "ffprobe -v error -select_streams v:0 -show_entries stream=bit_rate -of default=noprint_wrappers=1:nokey=1 \"$videoPath\"";
                exec($bitrateCmd, $bitrateOutput, $bitrateReturnVar);
                $bitrate = ($bitrateReturnVar === 0 && !empty($bitrateOutput[0])) ?
                    round($bitrateOutput[0] / 1000) . ' kbps' : 'Unknown';

                // Get codec information
                $codecCmd = "ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 \"$videoPath\"";
                exec($codecCmd, $codecOutput, $codecReturnVar);
                $codec = ($codecReturnVar === 0 && !empty($codecOutput[0])) ?
                    $codecOutput[0] : 'Unknown';

                $videoInfo = [
                    'filename' => $fileName,
                    'duration' => $duration,
                    'width' => $videoWidth,
                    'height' => $videoHeight,
                    'path' => $videoPath,
                    'web_path' => preg_replace('/^.*\/uploads\//', 'uploads/', $videoPath),
                    'bitrate' => $bitrate,
                    'codec' => $codec
                ];

                // If end time was not specified, set it to the video duration
                if (empty($endTime) || $endTime === '0') {
                    $endTime = (string)$duration;
                }

                // Calculate estimated file size
                $clipDuration = parseTimeInput($endTime) - parseTimeInput($startTime);
                $estimatedFileSize = calculateEstimatedFileSize(
                    $clipDuration,
                    $width ?: $videoWidth,
                    $height ?: $videoHeight,
                    $quality
                );

                // Generate clip
                $uniqueId = time() . '_' . mt_rand(1000, 9999);
                $outputFilename = "clip_{$uniqueId}.mp4";
                $outputPath = $outputDir . '/' . $outputFilename;

                $result = generateClip($videoPath, $startTime, $endTime, $width, $height, $quality, $outputPath);

                if ($result['success']) {
                    $result['download_url'] = 'clips/' . $outputFilename;
                    $result['video_title'] = $fileName;
                    $result['video_path'] = $videoInfo['web_path'];
                    $result['video_info'] = $videoInfo;
                    $result['estimated_size'] = $estimatedFileSize;
                    $result['keep_video'] = $videoPath;
                }
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Failed to get video dimensions',
                    'keep_video' => $videoPath
                ];
            }
        } else {
            $result = [
                'success' => false,
                'message' => 'Failed to get video duration',
                'keep_video' => $videoPath
            ];
        }
    }
    // Check if a new file was uploaded
    elseif (isset($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['video']['tmp_name'];
        $fileName = $_FILES['video']['name'];
        $fileType = $_FILES['video']['type'];
        $fileSize = $_FILES['video']['size'];

        // Validate the file type
        if (strpos($fileType, 'video/mp4') === false) {
            $result = [
                'success' => false,
                'message' => 'Please upload an MP4 video file'
            ];
        }
        // Check file size (limit to 500MB)
        elseif ($fileSize > 500 * 1024 * 1024) {
            $result = [
                'success' => false,
                'message' => 'File size exceeds the limit (500MB)'
            ];
        } else {
            // Create upload and output directories if they don't exist
            $uploadDir = __DIR__ . '/uploads';
            $outputDir = __DIR__ . '/clips';

            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Generate unique filenames
            $uniqueId = time() . '_' . mt_rand(1000, 9999);
            $videoFilename = $uniqueId . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $fileName);
            $videoPath = $uploadDir . '/' . $videoFilename;

            // Move the uploaded file
            if (move_uploaded_file($tmpName, $videoPath)) {
                // Get video information
                $ffprobeCmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"$videoPath\"";
                exec($ffprobeCmd, $durationOutput, $durationReturnVar);

                if ($durationReturnVar === 0 && !empty($durationOutput[0])) {
                    $duration = (float)$durationOutput[0];

                    // Get video dimensions
                    $dimensionsCmd = "ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 \"$videoPath\"";
                    exec($dimensionsCmd, $dimensionsOutput, $dimensionsReturnVar);

                    if ($dimensionsReturnVar === 0 && !empty($dimensionsOutput[0])) {
                        list($videoWidth, $videoHeight) = explode('x', $dimensionsOutput[0]);

                        // Get video bitrate
                        $bitrateCmd = "ffprobe -v error -select_streams v:0 -show_entries stream=bit_rate -of default=noprint_wrappers=1:nokey=1 \"$videoPath\"";
                        exec($bitrateCmd, $bitrateOutput, $bitrateReturnVar);
                        $bitrate = ($bitrateReturnVar === 0 && !empty($bitrateOutput[0])) ?
                            round($bitrateOutput[0] / 1000) . ' kbps' : 'Unknown';

                        // Get codec information
                        $codecCmd = "ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 \"$videoPath\"";
                        exec($codecCmd, $codecOutput, $codecReturnVar);
                        $codec = ($codecReturnVar === 0 && !empty($codecOutput[0])) ?
                            $codecOutput[0] : 'Unknown';

                        $videoInfo = [
                            'filename' => $fileName,
                            'duration' => $duration,
                            'width' => $videoWidth,
                            'height' => $videoHeight,
                            'path' => $videoPath,
                            'web_path' => 'uploads/' . $videoFilename,
                            'bitrate' => $bitrate,
                            'codec' => $codec
                        ];

                        // If end time was not specified, set it to the video duration
                        if (empty($endTime) || $endTime === '0') {
                            $endTime = (string)$duration;
                        }

                        // Calculate estimated file size
                        $clipDuration = parseTimeInput($endTime) - parseTimeInput($startTime);
                        $estimatedFileSize = calculateEstimatedFileSize(
                            $clipDuration,
                            $width ?: $videoWidth,
                            $height ?: $videoHeight,
                            $quality
                        );

                        // Generate clip
                        $outputFilename = "clip_{$uniqueId}.mp4";
                        $outputPath = $outputDir . '/' . $outputFilename;

                        $result = generateClip($videoPath, $startTime, $endTime, $width, $height, $quality, $outputPath);

                        if ($result['success']) {
                            $result['download_url'] = 'clips/' . $outputFilename;
                            $result['video_title'] = $fileName;
                            $result['video_path'] = 'uploads/' . $videoFilename;
                            $result['video_info'] = $videoInfo;
                            $result['estimated_size'] = $estimatedFileSize;
                            $result['keep_video'] = $videoPath;
                        }
                    } else {
                        $result = [
                            'success' => false,
                            'message' => 'Failed to get video dimensions'
                        ];
                    }
                } else {
                    $result = [
                        'success' => false,
                        'message' => 'Failed to get video duration'
                    ];
                }
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Failed to move uploaded file'
                ];
            }
        }
    } else {
        $uploadError = $_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errorMessage = 'Please select a video file to upload';

        switch ($uploadError) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMessage = 'The uploaded file exceeds the maximum file size limit';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMessage = 'The file was only partially uploaded';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $errorMessage = 'Missing a temporary folder';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $errorMessage = 'Failed to write file to disk';
                break;
            case UPLOAD_ERR_EXTENSION:
                $errorMessage = 'A PHP extension stopped the file upload';
                break;
        }

        $result = [
            'success' => false,
            'message' => $errorMessage
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MP4 Video Thumbnail Generator</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
        }

        a {
            color: #ff5538;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo {
            max-width: 150px;
            height: auto;
            margin: 3.6rem 0;
        }

        .container {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        input[type="file"] {
            padding: 8px 0;
        }

        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .progress-container {
            width: 100%;
            height: 20px;
            background-color: #f3f3f3;
            border-radius: 4px;
            margin-top: 10px;
            overflow: hidden;
            display: none;
        }

        .progress-bar {
            height: 100%;
            background-color: #4CAF50;
            width: 0%;
            text-align: center;
            line-height: 20px;
            color: white;
            transition: width 0.3s;
        }

        button {
            background-color: #ff5538;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }

        button:hover {
            background-color: #e64a30;
        }

        button:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }

        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
        }

        .success {
            background-color: #fff0ee;
            border: 1px solid #ffded8;
            color: #c54230;
        }

        .error {
            background-color: #f2dede;
            border: 1px solid #ebccd1;
            color: #a94442;
        }

        .video-preview {
            margin-top: 20px;
        }

        .video-preview video {
            max-width: 100%;
            border: 1px solid #ddd;
        }

        .dimensions {
            display: flex;
            gap: 10px;
        }

        .dimensions input {
            width: calc(50% - 5px);
        }

        .info {
            background-color: #fff0ee;
            border: 1px solid #ffded8;
            color: #c54230;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .video-details {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
        }

        .video-details p {
            margin: 5px 0;
        }

        .range-container {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 4px;
            border: 1px solid #e9ecef;
        }

        .range-slider {
            width: 100%;
            display: flex;
            flex-direction: column;
        }

        .time-inputs {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .time-input {
            width: 48%;
        }

        .slider-container {
            position: relative;
            width: 100%;
            height: 30px;
            margin: 10px 0;
        }

        .slider-track {
            position: absolute;
            width: 100%;
            height: 6px;
            background-color: #ddd;
            top: 12px;
            border-radius: 3px;
        }

        .slider-range {
            position: absolute;
            height: 6px;
            background-color: #ff5538;
            top: 12px;
            border-radius: 3px;
        }

        .slider-thumb {
            position: absolute;
            width: 18px;
            height: 18px;
            background-color: #ff5538;
            border-radius: 50%;
            top: 6px;
            cursor: pointer;
            z-index: 2;
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
        }

        .time-display {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 14px;
        }

        .time-marker {
            font-size: 12px;
            color: #666;
            position: absolute;
            transform: translateX(-50%);
        }

        .quality-options {
            display: flex;
            gap: 10px;
            margin-top: 5px;
        }

        .quality-option {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
            cursor: pointer;
        }

        .quality-option.selected {
            background-color: #fff0ee;
            border-color: #ff5538;
            color: #ff5538;
        }

        .dimension-note {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .preview-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }

        .preview-btn {
            background-color: #ff5538;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .preview-btn:hover {
            background-color: #e64a30;
        }

        .small-btn {
            background-color: #6c757d;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-left: 10px;
        }

        .small-btn:hover {
            background-color: #5a6268;
        }

        .current-video-info {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .current-video-info p {
            margin: 0;
        }

        .cleanup-info {
            font-size: 11px;
            color: #777;
            margin-top: 30px;
            text-align: center;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #666;
            font-size: 14px;
        }

        .footer a {
            color: #ff5538;
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>

    <div class="logo-container">
        <img src="logo.png" alt="Logo" class="logo">
    </div>

    <h1>MP4 Video Thumbnail Generator</h1>
    <?php if (!isset($result['keep_video'])): ?>

        <div class="info">
            <p>This tool allows you to upload an MP4 video, select a precise frame by setting a timecode, and export it as a web-optimized MP4 clip without audio.</p>
            <p>Maximum upload size: <?php echo round($maxUploadSize / (1024 * 1024), 2); ?> MB</p>
            <p><strong>Note:</strong> The output is optimized for web delivery with the following features:</p>
            <ul>
                <li>H.264 encoding with Main profile (Level 3.1) for maximum compatibility</li>
                <li>YUV420p pixel format for broad device support</li>
                <li>Fast start flag for immediate playback without downloading the entire file</li>
                <li>No audio track to reduce file size</li>
            </ul>
        </div>

    <?php endif; ?>

    <div class="container">
        <form method="post" action="" enctype="multipart/form-data" id="uploadForm">
            <div class="form-group">
                <label for="video">Upload MP4 Video:</label>
                <?php if (isset($result['keep_video'])): ?>
                    <div class="current-video-info">
                        <p>Using video: <strong><?php echo htmlspecialchars(basename($result['keep_video'])); ?></strong></p>
                        <input type="hidden" name="keep_video" value="<?php echo htmlspecialchars($result['keep_video']); ?>">
                        <button type="button" id="change-video-btn" class="small-btn">Change Video</button>
                    </div>
                    <div id="video-upload-field" style="display:none">
                        <input type="file" id="video" name="video" accept="video/mp4">
                    </div>
                <?php else: ?>
                    <input type="file" id="video" name="video" accept="video/mp4" required>
                <?php endif; ?>
                <div class="progress-container" id="progressContainer">
                    <div class="progress-bar" id="progressBar">0%</div>
                </div>
            </div>

            <div id="video-preview-container" <?php echo isset($result['keep_video']) ? '' : 'style="display:none"'; ?>>
                <h3>Video Preview</h3>
                <video id="video-preview" controls width="100%" <?php echo isset($result['video_path']) ? 'src="' . htmlspecialchars($result['video_path']) . '"' : ''; ?>></video>
                <div class="preview-controls">
                    <button type="button" id="preview-start-btn" class="preview-btn">Set Current Time as Start</button>
                    <span id="current-time">00:00:00</span>
                    <button type="button" id="preview-end-btn" class="preview-btn">Set Current Time as End</button>
                </div>
            </div>

            <div class="range-container" id="range-container" <?php echo isset($result['keep_video']) ? '' : 'style="display:none"'; ?>>
                <h3>Select Clip Range</h3>
                <div class="range-slider">
                    <div class="time-inputs">
                        <div class="time-input">
                            <label for="start_time">Start Time:</label>
                            <input type="text" id="start_time" name="start_time" placeholder="00:00:00" value="<?php echo $_POST['start_time'] ?? '0'; ?>">
                        </div>
                        <div class="time-input">
                            <label for="end_time">End Time:</label>
                            <input type="text" id="end_time" name="end_time" placeholder="00:00:00" value="<?php echo $_POST['end_time'] ?? ''; ?>">
                        </div>
                    </div>
                    <div class="slider-container">
                        <div class="slider-track"></div>
                        <div class="slider-range" id="slider-range"></div>
                        <div class="slider-thumb" id="start-thumb"></div>
                        <div class="slider-thumb" id="end-thumb"></div>
                        <div id="time-markers"></div>
                    </div>
                    <div class="time-display">
                        <span id="start-time-display">00:00:00</span>
                        <span id="duration-display">Duration: 0 seconds</span>
                        <span id="end-time-display">00:00:00</span>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Dimensions:</label>
                <div class="dimensions">
                    <input type="number" id="width" name="width" placeholder="Width (px)" value="<?php echo $_POST['width'] ?? ''; ?>" min="1">
                    <input type="number" id="height" name="height" placeholder="Height (px)" value="<?php echo $_POST['height'] ?? ''; ?>" min="1">
                </div>
                <div class="dimension-note" id="dimension-note">
                    Leave one dimension empty to maintain aspect ratio
                </div>
            </div>

            <div class="form-group">
                <label for="quality">Output Quality:</label>
                <input type="hidden" id="quality" name="quality" value="<?php echo isset($_POST['quality']) ? htmlspecialchars($_POST['quality']) : 'medium'; ?>">
                <?php $qualityValue = isset($_POST['quality']) ? $_POST['quality'] : 'medium'; ?>
                <div class="quality-options">
                    <div class="quality-option <?php echo ($qualityValue == 'lowest') ? 'selected' : ''; ?>" data-quality="lowest">Lowest</div>
                    <div class="quality-option <?php echo ($qualityValue == 'low') ? 'selected' : ''; ?>" data-quality="low">Low</div>
                    <div class="quality-option <?php echo ($qualityValue == 'medium' || !$qualityValue) ? 'selected' : ''; ?>" data-quality="medium">Medium</div>
                    <div class="quality-option <?php echo ($qualityValue == 'high') ? 'selected' : ''; ?>" data-quality="high">High</div>
                    <div class="quality-option <?php echo ($qualityValue == 'highest') ? 'selected' : ''; ?>" data-quality="highest">Highest</div>
                </div>
                <div id="estimated-size" class="dimension-note">
                    Estimated file size: Calculating...
                </div>
            </div>

            <button type="submit" id="submitBtn">Generate Thumbnail</button>
        </form>
    </div>

    <?php if ($result): ?>
        <div class="result <?php echo $result['success'] ? 'success' : 'error'; ?>">
            <h3><?php echo $result['success'] ? 'Success!' : 'Error'; ?></h3>
            <p><?php echo $result['success'] ? 'Thumbnail generated successfully.' : $result['message']; ?></p>
        </div>

        <?php if ($result['success']): ?>
            <div class="video-preview">
                <h3>Generated Thumbnail</h3>
                <video controls width="640">
                    <source src="<?php echo htmlspecialchars($result['download_url']); ?>" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
                <p>
                    <a href="<?php echo htmlspecialchars($result['download_url']); ?>" download>Download MP4 Thumbnail</a>
                </p>
                <p>
                    Clip details: <?php echo formatTime($result['start']); ?> to <?php echo formatTime($result['end']); ?>
                    (Duration: <?php echo round($result['duration'], 2); ?> seconds)
                </p>
                <?php if (isset($result['estimated_size'])): ?>
                    <p>
                        <strong>Estimated file size:</strong> <?php echo $result['estimated_size']; ?> (Actual size may vary)
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($videoInfo): ?>
        <div class="video-details">
            <h3>Uploaded Video Information</h3>
            <p><strong>Filename:</strong> <?php echo htmlspecialchars($videoInfo['filename']); ?></p>
            <p><strong>Duration:</strong> <?php echo formatTime($videoInfo['duration']); ?> (<?php echo round($videoInfo['duration'], 2); ?> seconds)</p>
            <p><strong>Dimensions:</strong> <?php echo $videoInfo['width']; ?> × <?php echo $videoInfo['height']; ?> pixels</p>
            <p><strong>Codec:</strong> <?php echo $videoInfo['codec']; ?></p>
            <p><strong>Bitrate:</strong> <?php echo $videoInfo['bitrate']; ?></p>
        </div>
    <?php endif; ?>

    <div class="cleanup-info">
        Files and generated thumbnails are automatically cleaned up after 10 minutes.
        Cleanup status: Processed <?php echo $cleanupResult['processed']; ?> files, deleted <?php echo $cleanupResult['deleted']; ?> old files.
    </div>

    <div class="footer">
        Powered by <a href="https://stirtingale.com" target="_blank">stirtingale.com</a>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('uploadForm');
            const fileInput = document.getElementById('video');
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');
            const submitBtn = document.getElementById('submitBtn');
            const videoPreviewContainer = document.getElementById('video-preview-container');
            const videoPreview = document.getElementById('video-preview');
            const rangeContainer = document.getElementById('range-container');
            const startTimeInput = document.getElementById('start_time');
            const endTimeInput = document.getElementById('end_time');
            const startThumb = document.getElementById('start-thumb');
            const endThumb = document.getElementById('end-thumb');
            const sliderRange = document.getElementById('slider-range');
            const startTimeDisplay = document.getElementById('start-time-display');
            const endTimeDisplay = document.getElementById('end-time-display');
            const durationDisplay = document.getElementById('duration-display');
            const timeMarkers = document.getElementById('time-markers');
            const currentTime = document.getElementById('current-time');
            const previewStartBtn = document.getElementById('preview-start-btn');
            const previewEndBtn = document.getElementById('preview-end-btn');
            const widthInput = document.getElementById('width');
            const heightInput = document.getElementById('height');
            const dimensionNote = document.getElementById('dimension-note');
            const qualityOptions = document.querySelectorAll('.quality-option');
            const qualityInput = document.getElementById('quality');

            // Estimated size display element
            const estimatedSizeDisplay = document.getElementById('estimated-size');

            let videoDuration = 0;
            let startTime = 0;
            let endTime = 0;
            let isDraggingStart = false;
            let isDraggingEnd = false;

            // Handle file selection
            fileInput.addEventListener('change', function() {
                const file = this.files[0];
                if (!file) return;

                if (file.type !== 'video/mp4') {
                    alert('Please select an MP4 video file.');
                    return;
                }

                // Create a blob URL for the video preview
                const videoURL = URL.createObjectURL(file);
                videoPreview.src = videoURL;
                videoPreviewContainer.style.display = 'block';

                // Initialize video metadata
                videoPreview.onloadedmetadata = function() {
                    videoDuration = videoPreview.duration;
                    endTime = videoDuration;

                    // Initialize the range slider
                    rangeContainer.style.display = 'block';
                    updateSlider();
                    createTimeMarkers();

                    // Set initial values
                    startTimeInput.value = formatTime(0);
                    endTimeInput.value = formatTime(videoDuration);

                    // Update estimated file size
                    updateEstimatedSize();

                    console.log(`Video loaded: ${file.name} (${formatTime(videoDuration)})`);
                };

                // Update current time display during playback
                videoPreview.ontimeupdate = function() {
                    currentTime.textContent = formatTime(videoPreview.currentTime);
                };
            });

            // Set start/end time from video preview
            previewStartBtn.addEventListener('click', function() {
                startTime = videoPreview.currentTime;
                startTimeInput.value = formatTime(startTime);
                updateSlider();
            });

            previewEndBtn.addEventListener('click', function() {
                endTime = videoPreview.currentTime;
                endTimeInput.value = formatTime(endTime);
                updateSlider();
            });

            // Handle manual time input
            startTimeInput.addEventListener('change', function() {
                startTime = parseTimeInput(this.value);
                if (startTime > endTime) {
                    startTime = endTime;
                    this.value = formatTime(startTime);
                }
                updateSlider();
            });

            endTimeInput.addEventListener('change', function() {
                endTime = parseTimeInput(this.value);
                if (endTime > videoDuration) {
                    endTime = videoDuration;
                    this.value = formatTime(endTime);
                }
                if (endTime < startTime) {
                    endTime = startTime;
                    this.value = formatTime(endTime);
                }
                updateSlider();
            });

            // Handle slider thumb drag
            startThumb.addEventListener('mousedown', function(e) {
                isDraggingStart = true;
                e.preventDefault();
            });

            endThumb.addEventListener('mousedown', function(e) {
                isDraggingEnd = true;
                e.preventDefault();
            });

            document.addEventListener('mousemove', function(e) {
                if (!isDraggingStart && !isDraggingEnd) return;

                const sliderRect = document.querySelector('.slider-container').getBoundingClientRect();
                const position = (e.clientX - sliderRect.left) / sliderRect.width;
                const newTime = Math.max(0, Math.min(position * videoDuration, videoDuration));

                if (isDraggingStart) {
                    startTime = Math.min(newTime, endTime - 0.5);
                    startTimeInput.value = formatTime(startTime);
                } else if (isDraggingEnd) {
                    endTime = Math.max(newTime, startTime + 0.5);
                    endTimeInput.value = formatTime(endTime);
                }

                updateSlider();
            });

            document.addEventListener('mouseup', function() {
                isDraggingStart = false;
                isDraggingEnd = false;
            });

            // Jump to time when clicking on the track
            document.querySelector('.slider-track').addEventListener('click', function(e) {
                const sliderRect = this.getBoundingClientRect();
                const position = (e.clientX - sliderRect.left) / sliderRect.width;
                const clickTime = position * videoDuration;

                // Determine which thumb to move based on which is closer
                if (Math.abs(clickTime - startTime) < Math.abs(clickTime - endTime)) {
                    startTime = Math.min(clickTime, endTime - 0.5);
                    startTimeInput.value = formatTime(startTime);
                } else {
                    endTime = Math.max(clickTime, startTime + 0.5);
                    endTimeInput.value = formatTime(endTime);
                }

                updateSlider();
            });

            // Handle automatic calculation of dimensions
            widthInput.addEventListener('input', function() {
                if (this.value) {
                    heightInput.value = '';
                    dimensionNote.textContent = 'Height will be calculated automatically to maintain aspect ratio';
                } else {
                    dimensionNote.textContent = 'Leave one dimension empty to maintain aspect ratio';
                }
                updateEstimatedSize();
            });

            heightInput.addEventListener('input', function() {
                if (this.value) {
                    widthInput.value = '';
                    dimensionNote.textContent = 'Width will be calculated automatically to maintain aspect ratio';
                } else {
                    dimensionNote.textContent = 'Leave one dimension empty to maintain aspect ratio';
                }
                updateEstimatedSize();
            });

            // Function to update estimated file size
            function updateEstimatedSize() {
                if (!videoPreview.duration) return;

                // Get current values
                const start = parseFloat(startTimeInput.value) || 0;
                const end = parseFloat(endTimeInput.value) || videoPreview.duration;
                const duration = end - start;
                const selectedWidth = parseInt(widthInput.value) || 0;
                const selectedHeight = parseInt(heightInput.value) || 0;
                const videoWidth = videoPreview.videoWidth;
                const videoHeight = videoPreview.videoHeight;
                const quality = qualityInput.value;

                // Determine final dimensions
                let finalWidth = videoWidth;
                let finalHeight = videoHeight;

                if (selectedWidth && !selectedHeight) {
                    finalWidth = selectedWidth;
                    finalHeight = Math.round((selectedWidth / videoWidth) * videoHeight);
                } else if (!selectedWidth && selectedHeight) {
                    finalHeight = selectedHeight;
                    finalWidth = Math.round((selectedHeight / videoHeight) * videoWidth);
                } else if (selectedWidth && selectedHeight) {
                    finalWidth = selectedWidth;
                    finalHeight = selectedHeight;
                }

                // Estimate file size based on quality
                let sizeMultiplier;
                switch (quality) {
                    case 'lowest':
                        sizeMultiplier = 0.2;
                        break;
                    case 'low':
                        sizeMultiplier = 0.4;
                        break;
                    case 'high':
                        sizeMultiplier = 1.5;
                        break;
                    case 'highest':
                        sizeMultiplier = 2.5;
                        break;
                    default:
                        sizeMultiplier = 1.0; // medium
                }

                // Calculate approximate bitrate in bits per second
                const pixelCount = finalWidth * finalHeight;
                const framerate = 30;
                const compressionFactor = 0.005; // Increased by 10x from previous value (0.0005)
                const bitrate = pixelCount * framerate * compressionFactor * sizeMultiplier;

                // Calculate size in bytes
                const sizeInBytes = (bitrate * duration) / 8;
                let estimatedSize;

                if (sizeInBytes < 1024 * 1024) {
                    estimatedSize = (sizeInBytes / 1024).toFixed(1) + ' KB';
                } else {
                    estimatedSize = (sizeInBytes / (1024 * 1024)).toFixed(1) + ' MB';
                }

                estimatedSizeDisplay.textContent = 'Estimated file size: ' + estimatedSize + ' (approximate)';
            }

            // Handle quality selection
            qualityOptions.forEach(option => {
                // Remove the default class setting - we'll use PHP to apply it server-side
                option.addEventListener('click', function() {
                    qualityOptions.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    qualityInput.value = this.dataset.quality;
                    updateEstimatedSize();
                });
            });

            // Update slider UI
            function updateSlider() {
                if (videoDuration === 0) return;

                const startPos = (startTime / videoDuration) * 100;
                const endPos = (endTime / videoDuration) * 100;

                startThumb.style.left = startPos + '%';
                endThumb.style.left = endPos + '%';
                sliderRange.style.left = startPos + '%';
                sliderRange.style.width = (endPos - startPos) + '%';

                startTimeDisplay.textContent = formatTime(startTime);
                endTimeDisplay.textContent = formatTime(endTime);
                durationDisplay.textContent = 'Duration: ' + formatDuration(endTime - startTime);

                // Update estimated file size when time range changes
                updateEstimatedSize();

                // Preview current selection
                previewSelection();
            }

            // Create time markers for the slider
            function createTimeMarkers() {
                timeMarkers.innerHTML = '';
                const markersCount = 5;

                for (let i = 0; i <= markersCount; i++) {
                    const position = (i / markersCount) * 100;
                    const time = (i / markersCount) * videoDuration;

                    const marker = document.createElement('div');
                    marker.className = 'time-marker';
                    marker.textContent = formatTime(time);
                    marker.style.left = position + '%';
                    marker.style.top = '25px';

                    timeMarkers.appendChild(marker);
                }
            }

            // Jump to selection start in video preview
            function previewSelection() {
                // Only jump to start time if video is not playing
                if (videoPreview.paused) {
                    videoPreview.currentTime = startTime;
                }
            }

            // Parse time input (supports HH:MM:SS, MM:SS, or seconds)
            function parseTimeInput(timeStr) {
                if (!timeStr) return 0;

                // Format: HH:MM:SS or HH:MM:SS.mmm
                if (/^\d+:\d+:\d+(\.\d+)?$/.test(timeStr)) {
                    const parts = timeStr.split(':');
                    return parseInt(parts[0]) * 3600 + parseInt(parts[1]) * 60 + parseFloat(parts[2]);
                }

                // Format: MM:SS or MM:SS.mmm
                if (/^\d+:\d+(\.\d+)?$/.test(timeStr)) {
                    const parts = timeStr.split(':');
                    return parseInt(parts[0]) * 60 + parseFloat(parts[1]);
                }

                // Just seconds
                return parseFloat(timeStr) || 0;
            }

            // Format time as HH:MM:SS
            function formatTime(seconds) {
                seconds = Math.max(0, seconds);
                const h = Math.floor(seconds / 3600);
                const m = Math.floor((seconds % 3600) / 60);
                const s = Math.floor(seconds % 60);

                return String(h).padStart(2, '0') + ':' +
                    String(m).padStart(2, '0') + ':' +
                    String(s).padStart(2, '0');
            }

            // Format duration in a human-readable way
            function formatDuration(seconds) {
                if (seconds < 60) {
                    return Math.round(seconds * 10) / 10 + ' seconds';
                }

                const minutes = Math.floor(seconds / 60);
                const remainingSeconds = Math.round((seconds % 60) * 10) / 10;

                if (minutes < 60) {
                    return `${minutes} min ${remainingSeconds} sec`;
                }

                const hours = Math.floor(minutes / 60);
                const remainingMinutes = minutes % 60;

                return `${hours} hr ${remainingMinutes} min ${remainingSeconds} sec`;
            }

            // Handle form submission
            form.addEventListener('submit', function(e) {
                const file = fileInput.files[0];
                if (!file) return;

                if (startTime >= endTime) {
                    alert('End time must be greater than start time.');
                    e.preventDefault();
                    return;
                }

                // Show progress bar for large files
                if (file.size > 5 * 1024 * 1024) { // 5MB
                    progressContainer.style.display = 'block';
                    submitBtn.disabled = true;

                    // Simulate upload progress
                    let progress = 0;
                    const interval = setInterval(function() {
                        progress += Math.random() * 10;
                        if (progress > 95) progress = 95; // Cap at 95% for simulation
                        progressBar.style.width = progress + '%';
                        progressBar.textContent = Math.round(progress) + '%';
                    }, 500);

                    // Store the interval ID in localStorage so we can continue progress on page reload
                    localStorage.setItem('uploadInterval', interval);
                }
            });

            // Handle video file change button
            const changeVideoBtn = document.getElementById('change-video-btn');
            const videoUploadField = document.getElementById('video-upload-field');

            if (changeVideoBtn) {
                changeVideoBtn.addEventListener('click', function() {
                    videoUploadField.style.display = 'block';
                    this.parentNode.style.display = 'none';
                    fileInput.required = true;
                });
            }

            <?php if (isset($result['keep_video']) && isset($videoInfo)): ?>
                // Initialize slider with existing video data
                document.addEventListener('DOMContentLoaded', function() {
                    // Wait for video metadata to be loaded
                    videoPreview.addEventListener('loadedmetadata', function() {
                        videoDuration = videoPreview.duration;
                        // Set start and end times from form values
                        startTime = parseTimeInput('<?php echo $startTime; ?>');
                        endTime = parseTimeInput('<?php echo $endTime; ?>');

                        // Update the slider with these values
                        updateSlider();
                        createTimeMarkers();
                        updateEstimatedSize();
                    });

                    // If video is already loaded, initialize immediately
                    if (videoPreview.readyState >= 1) {
                        videoDuration = videoPreview.duration;
                        startTime = parseTimeInput('<?php echo $startTime; ?>');
                        endTime = parseTimeInput('<?php echo $endTime; ?>');
                        updateSlider();
                        createTimeMarkers();
                        updateEstimatedSize();
                    }
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>