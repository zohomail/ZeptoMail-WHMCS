<?php

namespace WHMCS\Module\Mail;

use WHMCS\Authentication\CurrentUser;
use WHMCS\Exception\Mail\SendFailure;
use WHMCS\Exception\Module\InvalidConfiguration;
use WHMCS\Mail\Message;
use WHMCS\Module\Contracts\SenderModuleInterface;
use WHMCS\Module\MailSender\DescriptionTrait;
use WHMCS\Config\Setting;

/**
 * ZeptoMail
 *
 * @copyright Copyright (c) WHMCS Limited 2005-2020
 * @license http://www.example.com/
 */
class ZeptoMail implements SenderModuleInterface
{
    use DescriptionTrait;

    /**
     * Provider settings.
     *
     * @return array
     */
    public function settings()
    {
        return [
            "domain_name" => [
                "FriendlyName" => "Host",
                "Type" => "dropdown",
                "Options" => [
                    "com" => "api.zeptomail.com",
                    "eu" => "api.zeptomail.eu",
                    "in" => "api.zeptomail.in",
                    "com.cn" => "api.zeptomail.com.cn",
                    "com.au" => "api.zeptomail.com.au",
                ],
            ],
            "sendmailtoken" => [
                "FriendlyName" => "Send Mail Token",
                "Type" => "text",
                "Description" =>
                    "<span style=\"font-size: 12px;\">Send Mail token of the relevant Mail Agent.</span>",
            ],
            "bouncereturnpath" => [
                "FriendlyName" => "Bounce Return Path",
                "Type" => "text",
                "Description" =>
                    "<span style=\"font-size: 12px;\">Bounce email address configured for the relevant Mail Agent.</span>",
            ],
            "fromemailaddress" => [
                "FriendlyName" => "From Email Address",
                "Type" => "text",
                "Description" =>
                    "<span style=\"font-size: 12px;\">The email address that will be used as the From address.</span>",
            ],
            "fromname" => [
                "FriendlyName" => "From Name",
                "Type" => "text",
                "Description" =>
                    "<span style=\"font-size: 12px;\">The sender name displayed on the emails sent.</span>",
            ],
            "mail_format" => [
                "FriendlyName" => "Mail Format",
                "Type" => "dropdown",
                "Options" => [
                    "option1" => "Plaintext",
                    "option2" => "HTML",
                ],
            ],
        ];
    }

    /**
     * Module name used internally
     *
     * @return string
     */
    public function getName()
    {
        return "ZeptoMail";
    }

    /**
     * Module name shown in the Admin Area
     *
     * @return string
     */
    public function getDisplayName()
    {
        return "ZeptoMail";
    }

    /**
     * Test connection.
     *
     * @param array $settings
     *
     * @return array
     */
    public function testConnection(array $settings)
    {
        try {
            $sendmailtoken = $settings["sendmailtoken"];
            $bounce_address = $settings["bouncereturnpath"];
            $from_email_address = $settings["fromemailaddress"];
            $from_name = $settings["fromname"];
            $mail_format = $settings["mail_format"];

            $request_data = [
                "bounce_address" => $bounce_address,
                "from" => [
                    "address" => $from_email_address,
                    "name" => $from_name,
                ],
                "to" => [
                    [
                        "email_address" => [
                            "address" => $settings["fromemailaddress"],
                            "name" => $from_name,
                        ],
                    ],
                ],
                "subject" => "ZeptoMail plugin for WHMCS - Test Email",
            ];

            if ($mail_format === "option1") {
                $request_data["textbody"] = "Hello,

We're glad you're using our ZeptoMail plugin. This is a test email to verify your configuration details. Thank you for choosing ZeptoMail for your transactional email needs.

Team ZeptoMail";
            } elseif ($mail_format === "option2") {
                $request_data[
                    "htmlbody"
                ] = "<html><body><p>Hello,</p><br><br><p>We're glad you're using our ZeptoMail plugin. This is a test email to verify your configuration details. 
          Thank you for choosing ZeptoMail for your transactional email needs.<p><br><br>Team ZeptoMail";
            }

            $request_body = json_encode($request_data);

            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL =>
                    "https://api.zeptomail." .
                    $settings["domain_name"] .
                    "/v1.1/email",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => $request_body,
                CURLOPT_HTTPHEADER => [
                    "accept: application/json",
                    "authorization: " . $settings["sendmailtoken"],
                    "cache-control: no-cache",
                    "content-type: application/json",
                ],
            ]);
            $response = curl_exec($curl);
            curl_close($curl);
            $decodedData = json_decode($response, true);
            if (isset($decodedData["data"]) && !empty($decodedData["data"])) {
            } else {
                if (
                    isset($decodedData["error"]) &&
                    !empty($decodedData["error"])
                ) {
                    if (
                        isset($decodedData["error"]["details"][0]["message"]) &&
                        $decodedData["error"]["details"][0]["message"] ===
                            "Invalid API Token found"
                    ) {
                        $message =
                            "Configuration failed. Enter a valid Send Mail token and try again.";
                    }

                    if (
                        !empty($decodedData["error"]["details"][0]["target"]) &&
                        $decodedData["error"]["details"][0]["target"] ===
                            "bounce_address"
                    ) {
                        $message =
                            "Configuration failed. Enter a valid bounce address and try again.";
                    }

                    if (
                        !empty($decodedData["error"]["details"][0]["target"]) &&
                        $decodedData["error"]["details"][0]["target"] === "from"
                    ) {
                        $message =
                            "Configuration failed. Enter a valid From address and try again.";
                    }
                    throw new \Exception($message);
                }
            }
        } catch (Exception $e) {
            throw new Exception(
                "Unable to send a Test Message: " . $e->getMessage()
            );
        }
    }

    /**
     * This is responsible for delivering mail to the mail provider.
     *
     * @param array $settings
     * @param Message $message
     */
    public function send(array $settings, Message $message)
    {
        try {
            $mail_format = $settings["mail_format"];
            $postFields = [
                "bounce_address" => $settings["bouncereturnpath"],
                "from" => [
                    "address" => $message->getFromEmail(),
                    "name" => $message->getFromName(),
                ],
                "subject" => $message->getSubject(),
            ];
            $inlineImages = [];
            if ($mail_format === "option1") {
                $postFields["textbody"] = $message->getPlainText();
            } elseif ($mail_format === "option2") {
                $postFields["htmlbody"] = $message->getBody();

                preg_match_all(
                    "/<body\b[^>]*>(.*?)<\/body>/is",
                    $postFields["htmlbody"],
                    $bodyMatches
                );

                if (!empty($bodyMatches[1])) {
                    $bodyContent = $bodyMatches[1][0];

                    preg_match_all(
                        '/<img[^>]+src="([^">]+)"/',
                        $bodyContent,
                        $matches
                    );
                    $inlineImages = $matches[1];
                } else {
                    $inlineImages = [];
                }

                $inlineAttachments = [];

                $cids = [];
                foreach ($inlineImages as $imageUrl) {
                    $cid = uniqid("image");
                    $cids[$imageUrl] = $cid;

                    // Replace the image source URL with CID in the message body
                    $postFields["htmlbody"] = preg_replace(
                        '/(<img[^>]+src=)("' .
                            preg_quote($imageUrl, "/") .
                            '")/',
                        '$1"cid:' . $cid . '"',
                        $postFields["htmlbody"],
                        1
                    );

                    $imageData = file_get_contents($imageUrl);
                    $extension = pathinfo($imageUrl, PATHINFO_EXTENSION);

                    $inlineAttachments[] = [
                        "name" => basename($imageUrl),
                        "cid" => $cids[$imageUrl],
                        "mime_type" => "image/jpeg",
                        "content" => base64_encode($imageData),
                    ];
                }

                $postFields["inline_images"] = $inlineAttachments;
            }

            foreach ($message->getRecipients("to") as $to) {
                $postFields["to"][] = [
                    "email_address" => [
                        "address" => $to[0],
                        "name" => $to[1],
                    ],
                ];
            }

            foreach ($message->getRecipients("cc") as $cc) {
                $postFields["cc"][] = [
                    "email_address" => [
                        "address" => $cc[0],
                        "name" => $cc[1],
                    ],
                ];
            }
            foreach ($message->getRecipients("bcc") as $bcc) {
                $postFields["bcc"][] = [
                    "email_address" => [
                        "address" => $bcc[0],
                        "name" => $bcc[1],
                    ],
                ];
            }

            $attachments = [];
            foreach ($message->getAttachments() as $attachment) {
                if (array_key_exists("data", $attachment)) {
                    $content = $attachment["data"];
                    $filename = $attachment["filename"];
                } else {
                    $content = file_get_contents($attachment["filepath"]);
                    $filename = $attachment["filename"];
                }

                $attachments[] = [
                    "name" => $filename,
                    "mime_type" => "application/octet-stream",
                    "content" => base64_encode($content),
                ];
            }

            $postFields["attachments"] = json_encode($attachments);

            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL =>
                    "https://api.zeptomail." .
                    $settings["domain_name"] .
                    "/v1.1/email",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => json_encode($postFields),
                CURLOPT_HTTPHEADER => [
                    "accept: application/json",
                    "authorization: " . $settings["sendmailtoken"],
                    "cache-control: no-cache",
                    "content-type: application/json",
                ],
            ]);

            $response = curl_exec($curl);
            curl_close($curl);
            $decodedData = json_decode($response, true);

            if (isset($decodedData["data"]) && !empty($decodedData["data"])) {
            } else {
                if (
                    isset($decodedData["error"]) &&
                    !empty($decodedData["error"])
                ) {
                    if (
                        isset($decodedData["error"]["details"][0]["message"]) &&
                        $decodedData["error"]["details"][0]["message"] ===
                            "Invalid API Token found"
                    ) {
                        $message =
                            "Configuration failed. Enter a valid Send Mail token and try again.";
                    }

                    if (
                        !empty($decodedData["error"]["details"][0]["target"]) &&
                        $decodedData["error"]["details"][0]["target"] ===
                            "bounce_address"
                    ) {
                        $message =
                            "Configuration failed. Enter a valid bounce address and try again.";
                    }

                    if (
                        !empty($decodedData["error"]["details"][0]["target"]) &&
                        $decodedData["error"]["details"][0]["target"] === "from"
                    ) {
                        $message =
                            "Configuration failed. Enter a valid From address and try again.";
                    }
                    throw new \Exception($message);
                }
            }
        } catch (Exception $e) {
            throw new Exception(
                "Unable to send a Test Message: " . $e->getMessage()
            );
        }
    }
}
