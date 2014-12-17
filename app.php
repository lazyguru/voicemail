<?php

use Services_Twilio_Twiml as Twiml;
use Silex\Application;
use Silex\Provider\MonologServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use SendGrid\Email;

require 'vendor/autoload.php';

$app = new Application;

$app->register(new MonologServiceProvider(), [
    'monolog.logfile' => 'php://stdout',
    'monolog.level' => getenv('LOG_LEVEL')
        ? constant('Monolog\Logger::'.strtoupper(getenv('LOG_LEVEL')))
        : 'warning',
]);

$app['sendgrid'] = function ($app) {
    return new SendGrid(
        getenv('SENDGRID_USERNAME'),
        getenv('SENDGRID_PASSWORD')
    );
};

$app->post("/voice", function (Request $request, Application $app) {

    $twiml = new Twiml;
    $twiml->say("You have reached Joe Constant. Please leave a message after the beep, pressing any key when you are done.");
    $twiml->record([
        'maxLength' => 120,
        'action' => '/done',
        'transcribeCallback' => '/recordings',
        'transcribe' => true,
    ]);

    return new Response((string) $twiml);
});

$app->post("/done", function (Request $request, Application $app) {
    $twiml = new Twiml;
    $twiml->say("Thank you, good bye.");
    $twiml->hangup();

    return new Response((string)$twiml);
});

$app->post("/recordings", function (Request $request, Application $app) {

    $email = new Email();

    $to = getenv('VOICEMAIL_EMAIL_ADDRESS');
    $app['logger']->info("Sending notification to $to");

    $email->addTo($to)
        ->setFrom($to)
        ->setSubject("New Voicemail!")
        ->setText(sprintf(
            "SID: %s\nCaller: %s\nDuration: %s\nURL: %s\nText: %s\n",
            $request->get("CallSid"),
            $request->get("Caller"),
            $request->get("RecordingDuration"),
            $request->get("RecordingUrl"),
            $request->get("TranscriptionText")
        ));

    $rsp = $app['sendgrid']->send($email);

    if (is_object($rsp) && isset($rsp->errors)) {
        $app['logger']->error(json_encode($rsp));
    }

});

return $app;
