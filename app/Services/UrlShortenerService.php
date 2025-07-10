<?php

namespace App\Services;

use App\Models\Url;

class UrlShortenerService
{
    public function shorten(string $originalUrl, ?int $userId = null, ?string $comment = null): string
    {
        $url = Url::create([
            'user_id' => $userId,
            'original_url' => $originalUrl,
            'comment' => $comment,
        ]);

        $hash = $this->base62Encode($url->id);
        $url->update(['hash' => $hash]);

        return $url->hash;
    }

    private function base62Encode(int $id): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $base = strlen($chars);
        $result = '';

        do {
            $result = $chars[$id % $base] . $result;
            $id = intdiv($id, $base);
        } while ($id > 0);

        return $result;
    }
}
