<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>{{ $video->title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            margin: 0;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        video {
            max-width: 100%;
            max-height: 100vh;
        }
    </style>
</head>
<body>
    <video
        src="{{ asset('storage/' . $video->file_path) }}"
        controls
        autoplay
        playsinline
    ></video>
</body>
</html>
