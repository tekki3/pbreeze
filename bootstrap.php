<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80300) {
    throw new RuntimeException('PBreeze requires PHP 8.3 or later.');
}

require_once __DIR__ . '/framework/Env.php';
require_once __DIR__ . '/framework/Filesystem.php';
require_once __DIR__ . '/framework/Logger.php';
require_once __DIR__ . '/framework/Process.php';
require_once __DIR__ . '/framework/Http.php';
require_once __DIR__ . '/framework/UploadedFile.php';
require_once __DIR__ . '/framework/Uploads.php';
require_once __DIR__ . '/framework/Formatter.php';
require_once __DIR__ . '/framework/Strings.php';
require_once __DIR__ . '/framework/Ai.php';
require_once __DIR__ . '/framework/Cache.php';
require_once __DIR__ . '/framework/documents/DocumentFile.php';
require_once __DIR__ . '/framework/documents/Documents.php';
require_once __DIR__ . '/framework/documents/PdfDocument.php';
require_once __DIR__ . '/framework/documents/Spreadsheet.php';
require_once __DIR__ . '/framework/documents/WordDocument.php';
require_once __DIR__ . '/framework/Session.php';
require_once __DIR__ . '/framework/DatabaseSessionHandler.php';
require_once __DIR__ . '/framework/BotProtection.php';
require_once __DIR__ . '/framework/TemplateCompiler.php';
require_once __DIR__ . '/framework/TemplateContext.php';
require_once __DIR__ . '/framework/View.php';
require_once __DIR__ . '/framework/Validator.php';
require_once __DIR__ . '/framework/Router.php';
require_once __DIR__ . '/framework/Encryption.php';
require_once __DIR__ . '/framework/Database.php';
require_once __DIR__ . '/framework/Query.php';
require_once __DIR__ . '/framework/Model.php';
require_once __DIR__ . '/framework/Migrator.php';
require_once __DIR__ . '/framework/mail/MailSocket.php';
require_once __DIR__ . '/framework/mail/Message.php';
require_once __DIR__ . '/framework/mail/Mime.php';
require_once __DIR__ . '/framework/mail/Smtp.php';
require_once __DIR__ . '/framework/mail/Imap.php';
require_once __DIR__ . '/framework/mail/MailStore.php';
require_once __DIR__ . '/framework/mail/Mailer.php';
require_once __DIR__ . '/framework/mail/MailConsole.php';
require_once __DIR__ . '/framework/Auth.php';
require_once __DIR__ . '/framework/ApiTokens.php';
require_once __DIR__ . '/framework/Api.php';
require_once __DIR__ . '/framework/Clock.php';
require_once __DIR__ . '/framework/queue/Queue.php';
require_once __DIR__ . '/framework/scheduler/ScheduleExpression.php';
require_once __DIR__ . '/framework/scheduler/IntervalExpression.php';
require_once __DIR__ . '/framework/scheduler/CalendarExpression.php';
require_once __DIR__ . '/framework/scheduler/ScheduledTask.php';
require_once __DIR__ . '/framework/scheduler/Schedule.php';
require_once __DIR__ . '/framework/scheduler/SchedulerRepository.php';
require_once __DIR__ . '/framework/scheduler/Scheduler.php';
require_once __DIR__ . '/framework/worker/Worker.php';
require_once __DIR__ . '/framework/scheduler/SchedulerConsole.php';
require_once __DIR__ . '/framework/Application.php';
require_once __DIR__ . '/framework/Diagnostics.php';
require_once __DIR__ . '/framework/helpers.php';

\PBreeze\Env::load(__DIR__ . '/.env');
$config = require __DIR__ . '/config/app.php';
$config['mail'] = require __DIR__ . '/config/mail.php';
$config['ai'] = require __DIR__ . '/config/ai.php';
$config['cache'] = require __DIR__ . '/config/cache.php';
$config['scheduler'] = require __DIR__ . '/config/scheduler.php';
$config['queue'] = require __DIR__ . '/config/queue.php';

return new \PBreeze\Application(__DIR__, $config);
