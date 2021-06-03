<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class SecretsController extends Controller
{
    public function __construct()
    {
        //
    }
// todo 1-store string in cache instead of object 2- encrypt string

    public function getSecrets()
    {
        $secrets = Cache::rememberForever('awsSecrets', function () {
            return  Crypt::encryptString($this->fetchSecrets());
        });
        try {
            $decrypted = Crypt::decryptString($secrets);
        } catch (DecryptException $e) {
            $decrypted = null;
        }
        return json_decode($decrypted);
    }

    public function clearSecrets()
    {
        Cache::forget('awsSecrets');
        return Cache::get('awsSecrets');
    }

    public function isLatest(): bool
    {
        $aws = $this->fetchSecrets();
        $cache = Cache::get('awsSecrets');
        try {
            $cache = Crypt::decryptString($cache);
        } catch (DecryptException $e) {
            $cache = null;
        }
        $result = $aws === $cache; // todo test this
        if (!$result)
        {
            Cache::put('awsSecrets',  Crypt::encryptString($aws));
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
//        return json_decode($secret);
    }

}
