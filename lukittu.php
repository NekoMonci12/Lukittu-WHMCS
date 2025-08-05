<?php

/**
MIT License

Copyright (c) 2018-2019 Stepan Fedotov <stepan@crident.com>

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
**/

if(!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;

function lukittu_GetHostname(array $params) {
    $hostname = $params['serverhostname'];
    if ($hostname === '') throw new Exception('Could not find the panel\'s hostname - did you configure server group for the product?');

    // For whatever reason, WHMCS converts some characters of the hostname to their literal meanings (- => dash, etc) in some cases
    foreach([
        'DOT' => '.',
        'DASH' => '-',
    ] as $from => $to) {
        $hostname = str_replace($from, $to, $hostname);
    }

    if(ip2long($hostname) !== false) $hostname = 'http://' . $hostname;
    else $hostname = ($params['serversecure'] ? 'https://' : 'http://') . $hostname;

    return rtrim($hostname, '/');
}

function lukittu_GetTeamID(array $params) {
    $teamId = $params['serverusername'];
    if ($teamId === '') throw new Exception('Could not find the panel\'s TeamID - did you configure server group for the product?');

    // For whatever reason, WHMCS converts some characters of the hostname to their literal meanings (- => dash, etc) in some cases
    foreach([
        'DASH' => '-',
    ] as $from => $to) {
        $teamId = str_replace($from, $to, $teamId);
    }

    return $teamId;
}

function lukittu_API(array $params, $endpoint, array $data = [], $method = "GET", $dontLog = false) {
    $url = lukittu_GetHostname($params) . '/api/v1/dev/teams/' . lukittu_GetTeamID($params) . $endpoint;

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    curl_setopt($curl, CURLOPT_USERAGENT, "Lukittu");
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_POSTREDIR, CURL_REDIR_POST_301);
    curl_setopt($curl, CURLOPT_TIMEOUT, 5);

    $headers = [
        "Authorization: Bearer " . $params['serverpassword'],
    ];

    if($method === 'POST' || $method === 'PATCH') {
        $jsonData = json_encode($data);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
        array_push($headers, "Content-Type: application/json");
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    // Init return
    $responseData = [
        'status_code' => $httpCode,
    ];

    // Jika sukses decode
    if ($response !== false && ($decoded = json_decode($response, true)) !== null) {
        $responseData += $decoded; // merge hasil response
    } else if (!$dontLog) {
        // Log error CURL
        logModuleCall("Lukittu", "CURL ERROR", [
            'error' => $curlError,
            'url' => $url,
            'data' => $data,
            'method' => $method
        ], $response);
    }

    if (!$dontLog) {
        logModuleCall(
            "Lukittu",
            $method . " " . $url,
            json_encode($data),
            print_r($responseData, true),
            $curlError
        );
    }

    return $responseData;
}

function lukittu_Error($func, $params, Exception $err) {
    logModuleCall("Lukittu", $func, $params, $err->getMessage(), $err->getTraceAsString());
}

function lukittu_MetaData() {
    return [
        "DisplayName" => "Lukittu",
        "APIVersion" => "1.1",
        "RequiresServer" => true,
    ];
}

function lukittu_ConfigOptions() {
    return [
        "customerid" => [
            "FriendlyName" => "Customer ID",
            "Description" => "",
            "Type" => "text",
            "Size" => 25,
        ],
        "productid" => [
            "FriendlyName" => "Product ID",
            "Description" => "",
            "Type" => "text",
            "Size" => 25,
        ],
        "iplimit" => [
            "FriendlyName" => "IP Limit",
            "Description" => "",
            "Type" => "text",
            "Size" => 25,
            "Default" => 1,
        ],
        "seats" => [
            "FriendlyName" => "License Seats",
            "Description" => "",
            "Type" => "text",
            "Size" => 25,
            "Default" => 1,
        ],
        "expirationtype" => [
            "FriendlyName" => "Expiration Type",
            "Description" => "",
            "Type" => "dropdown",
            "Options" => [
                "NEVER" => "Never",
                "DATE" => "specific Date",
                "DURATION" => "Time Duration",
            ],
        ],
        "expirationstart" => [
            "FriendlyName" => "Expiration Start",
            "Description" => "",
            "Type" => "dropdown",
            "Options" => [
                "CREATION" => "When Created",
                "ACTIVATION" => "When Activated",
            ],
        ],
        "expirationdate" => [
            "FriendlyName" => "Expiration Date",
            "Description" => "",
            "Type" => "text",
            "Size" => 25,
            "Default" => "9999-12-31",
        ],
        "expirationdays" => [
            "FriendlyName" => "Expiration Days",
            "Description" => "",
            "Type" => "text",
            "Size" => 25,
            "Default" => 30,
        ]
    ];
}

function lukittu_GetOption(array $params, $id, $default = NULL) {
    $options = lukittu_ConfigOptions();

    $friendlyName = $options[$id]['FriendlyName'];
    if(isset($params['configoptions'][$friendlyName]) && $params['configoptions'][$friendlyName] !== '') {
        return $params['configoptions'][$friendlyName];
    } else if(isset($params['configoptions'][$id]) && $params['configoptions'][$id] !== '') {
        return $params['configoptions'][$id];
    } else if(isset($params['customfields'][$friendlyName]) && $params['customfields'][$friendlyName] !== '') {
        return $params['customfields'][$friendlyName];
    } else if(isset($params['customfields'][$id]) && $params['customfields'][$id] !== '') {
        return $params['customfields'][$id];
    }

    $found = false;
    $i = 0;
    foreach(lukittu_ConfigOptions() as $key => $value) {
        $i++;
        if($key === $id) {
            $found = true;
            break;
        }
    }

    if($found && isset($params['configoption' . $i]) && $params['configoption' . $i] !== '') {
        return $params['configoption' . $i];
    }

    return $default;
}

function lukittu_TestConnection(array $params) {
    $solutions = [
        0 => "Check module debug log for more detailed error.",
        401 => "Authorization header either missing or not provided.",
        403 => "Double check the password (which should be the API Key).",
        404 => "Result not found.",
        422 => "Validation error.",
        500 => "Panel errored, check panel logs.",
    ];

    $err = "";
    try {
        $response = lukittu_API($params, 'licenses', [], 'GET');

        if($response['status_code'] !== 200) {
            $status_code = $response['status_code'];
            $err = "Invalid status_code received: " . $status_code . ". Possible solutions: "
                . (isset($solutions[$status_code]) ? $solutions[$status_code] : "None.");
        } 
    } catch(Exception $e) {
        lukittu_Error(__FUNCTION__, $params, $e);
        $err = $e->getMessage();
    }

    return [
        "success" => $err === "",
        "error" => $err,
    ];
}

function lukittu_GetLicenseList(array $params, ?string $customerId = null, ?string $productId = null): array
{
    $allLicenses = [];
    $page = 1;

    do {
        $query = [
            'page' => $page
        ];

        if (!empty($customerId)) {
            $query['customerId'] = $customerId;
        }

        if (!empty($productId)) {
            $query['productId'] = $productId;
        }

        $endpoint = '/licenses?' . http_build_query($query);
        $response = lukittu_API($params, $endpoint, [], 'GET');

        if ($response['status_code'] >= 400) {
            $message = $response['message'] ?? 'Unknown error';
            throw new Exception("Lukittu API Error ({$response['status_code']}): $message");
        }

        $licenses = $response['data']['licenses'] ?? [];
        $hasNextPage = $response['data']['hasNextPage'] ?? false;

        $allLicenses = array_merge($allLicenses, $licenses);
        $page++;
    } while ($hasNextPage);

    return $allLicenses;
}

function lukittu_GetKey(array $params, string $username, string $serviceid): ?string
{
    $licenses = lukittu_GetLicenseList($params);

    foreach ($licenses as $license) {
        $metadata = $license['metadata'] ?? [];
        $matchedUsername = false;
        $matchedServiceId = false;

        foreach ($metadata as $meta) {
            if ($meta['key'] === 'username' && $meta['value'] === $username) {
                $matchedUsername = true;
            }
            if ($meta['key'] === 'serviceid' && $meta['value'] === $serviceid) {
                $matchedServiceId = true;
            }
        }

        if ($matchedUsername && $matchedServiceId) {
            return $license['licenseKey'];
        }
    }

    return null;
}


function lukittu_GenerateKey($inputString) {
    $licenseHashed = md5($inputString);
    $licenseObfuscated = substr($licenseHashed, 0, 12) 
                        . substr(strrev($licenseHashed), 10, 4) 
                        . strrev(substr($licenseHashed, 20, 12));
    $licenseFormatted = substr($licenseObfuscated, 0, 5) . '-' . 
                        substr($licenseObfuscated, 5, 5) . '-' . 
                        substr($licenseObfuscated, 10, 4) . '-' . 
                        substr($licenseObfuscated, 14, 7) . '-' . 
                        substr($licenseObfuscated, 21, 7);
    return strtoupper($licenseFormatted);
}

function lukittu_CreateLicenseIfNotExists(array $params, array $options): string
{
    $username = $options['username'];
    $serviceid = $options['serviceid'];

    $existingKey = lukittu_GetKey($params, $username, $serviceid);
    if (!empty($existingKey)) {
        return $existingKey;
    }

    $endpoint = "/licenses";

    $payload = [
        "customerIds" => [$options['customerId']],
        "productIds" => [$options['productId']],
        "expirationDate" => $options['expirationDate'] ?? null,
        "expirationDays" => isset($options['expirationDays']) ? (int)$options['expirationDays'] : null,
        "expirationStart" => $options['expirationStart'],
        "expirationType" => $options['expirationType'],
        "ipLimit" => isset($options['ipLimit']) ? (int)$options['ipLimit'] : 0,
        "metadata" => [
            [
                "key" => "serviceid",
                "value" => $serviceid,
                "locked" => true
            ],
            [
                "key" => "username",
                "value" => $username,
                "locked" => false
            ]
        ],
        "seats" => isset($options['seats']) ? (int)$options['seats'] : 1,
        "suspended" => $options['suspended'] ?? false,
        "sendEmailDelivery" => $options['sendEmailDelivery'] ?? false
    ];

    $response = lukittu_API($params, $endpoint, $payload, "POST");

    if (($response['status_code'] ?? 500) !== 200) {
        throw new Exception("Failed to create license. Status code: {$response['status_code']}");
    }

    return $response['data']['licenseKey'] ?? '';
}

function lukittu_CreateAccount(array $params)
{
    try {
        $username = $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'];
        $serviceid = 'WHMCS-' . $params['serviceid'];

        $options = [
            "customerId" => lukittu_GetOption($params, 'customerid'),
            "productId" => lukittu_GetOption($params, 'productid'),
            "expirationType" => lukittu_GetOption($params, 'expirationtype'),
            "expirationStart" => lukittu_GetOption($params, 'expirationstart'),
            "expirationDate" => lukittu_GetOption($params, 'expirationdate'),
            "expirationDays" => lukittu_GetOption($params, 'expirationdays'),
            "ipLimit" => lukittu_GetOption($params, 'iplimit'),
            "seats" => lukittu_GetOption($params, 'seats'),
            "suspended" => false,
            "sendEmailDelivery" => false,
            "username" => $username,
            "serviceid" => $serviceid
        ];

        lukittu_CreateLicenseIfNotExists($params, $options);
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function lukittu_SuspendAccount(array $params)
{
    try {
        // Generate key input
        $inputString = $params['serviceid'] . '-' . $params['username'];
        $keyResponse = lukittu_GetKey($params, lukittu_GenerateKey($inputString));

        // Validate API response
        $success = isset($keyResponse['success']) 
           && $keyResponse['success'] 
           && isset($keyResponse['data']['id']);

        if (!$success) {
            throw new Exception("Failed to check account. Response: " . json_encode($keyResponse));
        }


        // Construct endpoint
        $endpoint = '/' . $keyResponse['data']['id'];
        $name = $keyResponse['data']['name'];
        $notes = $keyResponse['data']['notes'];
        $limit = $keyResponse['data']['ipLimit'];
        $scope = $keyResponse['data']['licenseScope'];
        $vtokens = $keyResponse['data']['validationPoints'];
        $vlimit = $keyResponse['data']['validationLimit'];
        $rinterval = $keyResponse['data']['replenishInterval'];
        $licenseKey = $keyResponse['data']['licenseKey'];

        // Data payload
        $dataPayload = [
            "licenseKey" => $licenseKey,
            "active" => false,
            "name" => $name,
            "notes" => $notes,
            "ipLimit" => $limit,
            "licenseScope" => $scope,
            "expirationDate" => "9999-12-31T23:59:59",
            "validationPoints" => $vtokens,
            "validationLimit" => $vlimit,
            "replenishAmount" => $vtokens,
            "replenishInterval" => $rinterval,
        ];

        // Make API request
        $response = lukittu_API($params, $endpoint, $dataPayload, "PATCH");

        // Validate API response
        if (!isset($response['status_code']) || $response['status_code'] !== 200) {
            throw new Exception("Failed to suspend account. Response: " . json_encode($response));
        }

    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }

    return "success";
}


function lukittu_UnsuspendAccount(array $params)
{
    try {
        // Generate key input
        $inputString = $params['serviceid'] . '-' . $params['username'];
        $keyResponse = lukittu_GetKey($params, lukittu_GenerateKey($inputString));

        // Validate API response
        $success = isset($keyResponse['success']) 
            && $keyResponse['success'] 
            && isset($keyResponse['data']['id']);

        if (!$success) {
            throw new Exception("Failed to check account. Response: " . json_encode($keyResponse));
        }

        // Construct endpoint
        $endpoint = '/' . $keyResponse['data']['id'];
        $name = $keyResponse['data']['name'];
        $notes = $keyResponse['data']['notes'];
        $limit = $keyResponse['data']['ipLimit'];
        $scope = $keyResponse['data']['licenseScope'];
        $vtokens = $keyResponse['data']['validationPoints'];
        $vlimit = $keyResponse['data']['validationLimit'];
        $rinterval = $keyResponse['data']['replenishInterval'];
        $licenseKey = $keyResponse['data']['licenseKey'];

        // Data payload
        $dataPayload = [
            "licenseKey" => $licenseKey,
            "active" => true,
            "name" => $name,
            "notes" => $notes,
            "ipLimit" => $limit,
            "licenseScope" => $scope,
            "expirationDate" => "9999-12-31T23:59:59",
            "validationPoints" => $vtokens,
            "validationLimit" => $vlimit,
            "replenishAmount" => $vtokens,
            "replenishInterval" => $rinterval,
        ];

        // Make API request
        $response = lukittu_API($params, $endpoint, $dataPayload, "PATCH");

        // Validate API response
        if (!isset($response['status_code']) || $response['status_code'] !== 200) {
            throw new Exception("Failed to suspend account. Response: " . json_encode($response));
        }

    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }

    return "success";
}

function lukittu_TerminateAccount(array $params)
{
    try {
        $inputString = $params['serviceid'] . '-' . $params['username'];
        $keyResponse = lukittu_GetKey($params, lukittu_GenerateKey($inputString));

        if ($keyResponse['success']) {
            $endpoint = '/' . $keyResponse['data']['id'];
        } else {
            throw new Exception("Failed to check account. Status code: {$response['status_code']}");
        }

        $response = lukittu_API($params, $endpoint, [], "DELETE");

        if ($response['status_code'] !== 200) {
            throw new Exception("Failed to terminate account. Status code: {$response['status_code']}");
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function lukittu_ChangePassword(array $params)
{
    try {
        if($params['password'] === '') throw new Exception('The password cannot be empty.');
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function lukittu_ChangePackage(array $params)
{
    try {
        $active = true;
        $notes = lukittu_GetOption($params, 'notes', 'Created From WHMCS');
        $limit = lukittu_GetOption($params, 'limit');
        $scope = lukittu_GetOption($params, 'scope');
        $vtokens = lukittu_GetOption($params, 'vtokens', $limit * 3);
        $vlimit = lukittu_GetOption($params, 'vlimit', $vtokens * 3);
        $rinterval = lukittu_GetOption($params, 'rinterval', 'HOUR');

        $inputString = $params['serviceid'] . '-' . $params['username'];
        $keyResponse = lukittu_GetKey($params, lukittu_GenerateKey($inputString));

        if ($keyResponse['success']) {
            $endpoint = '/' . $keyResponse['data']['id'];
        } else {
            throw new Exception("Failed to check account. Status code: {$response['status_code']}");
        }
        $licenseKey = $keyResponse['data']['licenseKey'];

        $dataPayload = [
            "licenseKey" => $licenseKey,
            "active" => $active,
            "notes" => $notes,
            "ipLimit" => $limit,
            "licenseScope" => $scope,
            "expirationDate" => "9999-12-31T23:59:59",
            "validationPoints" => $vtokens,
            "validationLimit" => $vlimit,
            "replenishAmount" => $vtokens,
            "replenishInterval" => $rinterval,
        ];

        $response = lukittu_API($params, $endpoint, $dataPayload, "PATCH");

        if ($response['status_code'] !== 200) {
            throw new Exception("Failed to update account. Status code: {$response['status_code']}");
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function lukittu_Renew(array $params)
{
    try {
        $inputString = $params['serviceid'] . '-' . $params['username'];
        $keyResponse = lukittu_GetKey($params, lukittu_GenerateKey($inputString));
        if ($keyResponse['success']) {
            $endpoint = '/' . $keyResponse['data']['id'];
        } else {
            throw new Exception("Failed to check account. Status code: {$response['status_code']}");
        }

        $checker = lukittu_API($params, $endpoint, [], 'GET');

        if($checker['status_code'] == 200) {
            $active = true;
            $notes = lukittu_GetOption($params, 'notes', 'Created From WHMCS');
            $limit = lukittu_GetOption($params, 'limit');
            $scope = lukittu_GetOption($params, 'scope');
            $vtokens = lukittu_GetOption($params, 'vtokens', $limit * 3);
            $vlimit = lukittu_GetOption($params, 'vlimit', $vtokens * 3);
            $rinterval = lukittu_GetOption($params, 'rinterval', 'HOUR');

            $inputString = $params['serviceid'] . '-' . $params['username'];
            $endpoint = lukittu_GenerateKey($inputString);

            $dataPayload = [
                "licenseKey" => $licenseKey,
                "active" => $active,
                "notes" => $notes,
                "ipLimit" => $limit,
                "licenseScope" => $scope,
                "expirationDate" => "9999-12-31T23:59:59",
                "validationPoints" => $vtokens,
                "validationLimit" => $vlimit,
                "replenishAmount" => $vtokens,
                "replenishInterval" => $rinterval,
            ];

            $response = lukittu_API($params, $endpoint, $dataPayload, "PATCH");

            if ($response['status_code'] !== 200) {
                throw new Exception("Failed to execute command. Status code: {$response['status_code']}");
            }
        } else {
            $name = $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'];
            $active = true;
            $notes = lukittu_GetOption($params, 'notes', 'Created From WHMCS');
            $limit = lukittu_GetOption($params, 'limit');
            $scope = lukittu_GetOption($params, 'scope');
            $vtokens = lukittu_GetOption($params, 'vtokens', $limit * 3);
            $vlimit = lukittu_GetOption($params, 'vlimit', $vtokens * 3);
            $rinterval = lukittu_GetOption($params, 'rinterval', 'HOUR');
            $endpoint = "";

            $inputString = $params['serviceid'] . '-' . $params['username'];
            $licenseKey = lukittu_GenerateKey($inputString);

            $dataPayload = [
                "active" => $active,
                "name" => $name,
                "notes" => $notes,
                "ipLimit" => $limit,
                "licenseScope" => $scope,
                "expirationDate" => "9999-12-31T23:59:59",
                "validationPoints" => $vtokens,
                "validationLimit" => $vlimit,
                "replenishAmount" => $vtokens,
                "replenishInterval" => $rinterval,
                "licenseKey" => $licenseKey,
            ];

            $response = lukittu_API($params, $endpoint, $dataPayload, "POST");

            if ($response['status_code'] !== 201) {
                throw new Exception("Failed to execute command. Status code: {$response['status_code']}");
            }
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function lukittu_ClientArea($params) {
    $serviceid = 'WHMCS-' . $params['serviceid'];
    $username = $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'];
    $licenseKey = lukittu_GetKey($params, $username, $serviceid);

    return array(
        'tabOverviewReplacementTemplate' => 'templates/clientarea.tpl',
        'templateVariables' => array(
            'licensesKey' => $licenseKey
        ),
    );
}
