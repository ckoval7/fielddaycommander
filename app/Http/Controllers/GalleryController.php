<?php

namespace App\Http\Controllers;

use App\Models\Image;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GalleryController extends Controller
{
    public function thumbnail(Image $image): StreamedResponse|Response
    {
        $thumbnailPath = $this->getThumbnailPath($image->storage_path);

        if (! Storage::disk('local')->exists($thumbnailPath)) {
            // Fall back to original if thumbnail doesn't exist
            $thumbnailPath = $image->storage_path;
        }

        if (! Storage::disk('local')->exists($thumbnailPath)) {
            abort(404);
        }

        $etag = '"thumb-'.$image->id.'-'.substr($image->file_hash, 0, 12).'"';

        return Storage::disk('local')->response($thumbnailPath, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400, must-revalidate',
            'ETag' => $etag,
        ]);
    }

    public function image(Image $image): StreamedResponse|Response
    {
        if (! Storage::disk('local')->exists($image->storage_path)) {
            abort(404);
        }

        $etag = '"img-'.$image->id.'-'.substr($image->file_hash, 0, 12).'"';

        return Storage::disk('local')->response($image->storage_path, null, [
            'Content-Type' => $image->mime_type,
            'Cache-Control' => 'public, max-age=86400, must-revalidate',
            'ETag' => $etag,
        ]);
    }

    protected function getThumbnailPath(string $originalPath): string
    {
        $pathInfo = pathinfo($originalPath);

        return $pathInfo['dirname'].'/'.$pathInfo['filename'].'_thumb.jpg';
    }
}
