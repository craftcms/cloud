<?php

namespace craft\cloud\bref\curl;

final class CurlClient
{
    public function post(string $url, string $body)
    {
        $project = getenv('CRAFT_CLOUD_PROJECT_ID');

        $environment = getenv('CRAFT_CLOUD_ENVIRONMENT_ID');

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
            "User-Agent: Craft/Cloud/$project/$environment",
        ]);

        $responseBody = curl_exec($ch);

        $responseStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $curlError = curl_error($ch);

        curl_close($ch);

        return new CurlResponse($responseBody, $responseStatus, $curlError);
    }
}
