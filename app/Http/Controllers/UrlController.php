<?php

namespace App\Http\Controllers;

use App\Models\Url;
use Illuminate\Http\Request;

class UrlController extends Controller
{
    public function shortenLink($hash)
    {
        $find = Url::where('hash', $hash)->first();
        if (!$find) return response()->json(['error' => 'URL not found'], 404);
        return redirect($find->original_url);
    }
}
