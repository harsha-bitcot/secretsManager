<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request; // todo move everything to utils if no http(s) requests are involved?
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class SecretsController extends Controller
{
    /**
     * @var string
     */
    private $cacheKey;
    private $retryCount;

    public function __construct()
    {
        $this->cacheKey = 'awsSecrets';
        $this->retryCount = 'secretsRetry';
    }

    public function getSecrets()
    {
//        error_log('Some message here.');
        $secrets = Cache::rememberForever($this->cacheKey, function () {
            return  Crypt::encryptString($this->fetchSecrets());
        });
        try {
            $decrypted = Crypt::decryptString($secrets);
        } catch (DecryptException $e) {
            $decrypted = null;
        }
        return json_decode($decrypted);
    }

    public function clearSecrets(): bool
    {
        Cache::forget($this->cacheKey);
        return Cache::get($this->cacheKey) == null;
    }

    public function isLatest($key, $update = true): bool
    {
        $retryKey = $this->retryCount . $key;
        $retryCount = Cache::get($retryKey);
        switch (true) {
            /** @noinspection PhpDuplicateSwitchCaseBodyInspection */ case !is_numeric($retryCount):
                Cache::put($retryKey,  1);
                break;
            case $retryCount <= 10:
                Cache::increment($retryKey);
                break;
            case $retryCount > 10:
                return true;
            default:
                Cache::put($retryKey,  1);
                break;
        }
        $aws = $this->fetchSecrets();
        $cache = Cache::get($this->cacheKey);
        try {
            $cache = Crypt::decryptString($cache);
        } catch (DecryptException $e) {
            $cache = null;
        }
        $result = $aws === $cache;
        if (!$result && $update)
        {
            Cache::put($this->cacheKey,  Crypt::encryptString($aws));
            config(['secrets' => json_decode($aws)]);
        }
        return $result;
    }

    private function fetchSecrets()
    {
        // Create a Secrets Manager Client
        $client = new SecretsManagerClient([
            'profile' => 'poc',
            'version' => 'latest',
            'region' => 'us-east-2',
        ]);

        $secretName = 'test/local';

        try {
            $result = $client->getSecretValue([
                'SecretId' => $secretName,
            ]);

        } catch (AwsException $e) {
            $error = $e->getAwsErrorCode();
            if ($error == 'DecryptionFailureException') {
                // Secrets Manager can't decrypt the protected secret text using the provided AWS KMS key.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'InternalServiceErrorException') {
                // An error occurred on the server side.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'InvalidParameterException') {
                // You provided an invalid value for a parameter.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'InvalidRequestException') {
                // You provided a parameter value that is not valid for the current state of the resource.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'ResourceNotFoundException') {
                // We can't find the resource that you asked for.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
        }
        // Decrypts secret using the associated KMS CMK.
        // Depending on whether the secret is a string or binary, one of these fields will be populated.
        if (isset($result['SecretString'])) {
            $secret = $result['SecretString'];
        } else {
            $secret = base64_decode($result['SecretBinary']); // we wont be using this
        }

        //we are assuming that $secret will either contains a json string or null
        return $secret;
    }

}
