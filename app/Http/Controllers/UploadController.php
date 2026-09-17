<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    use \App\Http\Concerns\ApiResponse;

    /**
     * Upload a logo image to the public disk and return its URL.
     * Accepted: jpg, jpeg, png, webp, gif — max 2 MB.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
        ]);

        $file = $request->file('file');

        $path = $file->storeAs(
            'logos',
            Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: 'png'),
            'public'
        );

        return $this->ok([
            'path' => $path,
            // Built from the current request host so the URL works for the
            // calling frontend regardless of the configured APP_URL.
            'url' => url('storage/'.$path),
        ], 'File uploaded.', 201);
    }
}
