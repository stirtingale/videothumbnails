# MP4 Video Thumbnail Generator

A web-based tool that allows users to upload MP4 videos, select precise frames, and generate web-optimized video thumbnails.

## Features

- **Simple Video Upload**: Upload MP4 videos directly from your browser
- **Interactive Timeline**: Select the exact frame you want using a visual slider
- **Quality Control**: Choose from five quality presets (Lowest to Highest)
- **Dimension Control**: Set custom width or height while maintaining aspect ratio
- **File Size Estimation**: Get real-time estimates of output file size
- **Web Optimization**: All thumbnails are optimized for web delivery
- **No Audio**: Audio tracks are stripped to reduce file size
- **Auto Cleanup**: Files are automatically removed after 10 minutes

## Requirements

- PHP 7.2 or later
- FFmpeg installed on the server
- Write permissions for the directories
- Web server (Apache, Nginx, etc.)

## Installation

1. Copy all files to your web server directory
2. Create three subdirectories with write permissions:
   - `/uploads` - For temporary storage of uploaded videos
   - `/clips` - For storing generated thumbnails
   - `/thumbnails` - Alternative location for thumbnails
3. Ensure FFmpeg is installed and accessible from PHP
4. Place your logo.png in the same directory (300px wide recommended)

## Configuration Options

The script can be configured by modifying the following variables at the top of the index.php file:

- `$cleanupAge`: Time in seconds before files are cleaned up (default: 10 minutes)
- `$maxFilesToProcess`: Maximum number of files to check per cleanup run
- Quality presets can be adjusted in the `$qualityPresets` array

## FFmpeg Commands Used

The script uses the following FFmpeg parameters for optimal web delivery:

- `-c:v libx264`: H.264 video codec
- `-profile:v main`: Main profile for H.264
- `-level 3.1`: Compatibility level
- `-pix_fmt yuv420p`: Pixel format for maximum compatibility
- `-movflags +faststart`: Optimized for web streaming
- `-an`: No audio track

## Troubleshooting

If you encounter issues:

1. **Check FFmpeg Installation**: Run `ffmpeg -version` from the command line to verify FFmpeg is installed
2. **Directory Permissions**: Ensure the uploads, clips, and thumbnails directories are writable
3. **PHP Memory Limits**: For large videos, you may need to increase PHP memory limits
4. **File Upload Size**: Check your PHP configuration for maximum upload size limits

## Acknowledgements

Powered by [stirtingale.com](https://stirtingale.com)

---

This tool is designed to be simple yet powerful, allowing users to quickly generate web-optimized video thumbnails without requiring technical knowledge of video encoding.
