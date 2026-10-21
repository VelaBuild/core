<?php

namespace VelaBuild\Core\Http\Controllers\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The upload endpoint behind a form's file dropzone.
 *
 * A controller using this lists the permissions that may upload through it
 * in $mediaUploadGates; holding any one of them is enough. A controller that
 * lists none refuses everyone, so the endpoint is never open to any account
 * that merely signed in.
 */
trait MediaUploadingTrait
{
    public function storeMedia(Request $request)
    {
        $gates = property_exists($this, 'mediaUploadGates') ? $this->mediaUploadGates : [];
        abort_unless($gates !== [] && Gate::any($gates), 403, '403 Forbidden');

        // Validates file size
        if (request()->has('size')) {
            $this->validate(request(), [
                'file' => 'max:' . request()->input('size') * 1024,
            ]);
        }
        // If width or height is preset - we are validating it as an image
        if (request()->has('width') || request()->has('height')) {
            $this->validate(request(), [
                'file' => sprintf(
                    'image|dimensions:max_width=%s,max_height=%s',
                    request()->input('width', 100000),
                    request()->input('height', 100000)
                ),
            ]);
        }

        $path = storage_path('tmp/uploads');

        try {
            if (! file_exists($path)) {
                mkdir($path, 0755, true);
            }
        } catch (\Exception $e) {
        }

        $file = $request->file('file');

        $name = uniqid() . '_' . trim($file->getClientOriginalName());

        $file->move($path, $name);

        return response()->json([
            'name'          => $name,
            'original_name' => $file->getClientOriginalName(),
        ]);
    }
}
