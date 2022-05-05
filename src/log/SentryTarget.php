<?php

namespace diginov\sentrylogger\log;

use Craft;
use craft\helpers\App;

use Sentry;
use Sentry\Severity;
use Sentry\State\Scope;

use Twig\Environment as TwigEnvironment;

use Yii;
use yii\helpers\VarDumper;
use yii\i18n\PhpMessageSource;
use yii\log\Logger;
use yii\web\HttpException;

class SentryTarget extends \yii\log\Target
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool
     */
    public bool $anonymous = false;

    /**
     * @var string|null
     */
    public ?string $dsn = null;

    /**
     * @var string|null
     */
    public ?string $release = null;

    /**
     * @var string|null
     */
    public ?string $environment = null;

    /**
     * @var array
     */
    public array $options = [];

    /**
     * @var array
     */
    public array $exceptCodes = [403, 404];

    /**
     * @var array
     */
    public array $exceptPatterns = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if (!$this->enabled || !$this->dsn || !$this->levels) {
            return;
        }

        $except = [
            PhpMessageSource::class . ':*',
        ];

        foreach ($this->exceptCodes as $exceptCode) {
            if (preg_match('/^[1-5][0-9]{2}$/', $exceptCode)) {
                $except[] = HttpException::class . ':' . $exceptCode;
            }
        }

        $this->except = array_unique(array_merge($this->except, $except));

        Sentry\init(array_merge($this->getOptions()));
    }

    /**
     * @inheritdoc
     */
    public function export(): void
    {
        if (!$this->enabled || !$this->dsn || !$this->levels) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();
        $groups = [];

        if ($user) {
            $groups = array_map(static function($group): string {
                return $group->name;
            }, $user->getGroups());
        }

        $request = Craft::$app->getRequest();
        $messages = static::filterMessages($this->messages, $this->getLevels(), $this->categories, $this->except);

        foreach ($messages as $message) {
            Sentry\withScope(function(Scope $scope) use ($user, $groups, $request, $message) {
                [$message, $level, $category, $timestamp, $traces, $memory] = $message;

                foreach($this->exceptPatterns as $exceptPattern) {
                    if (!empty($exceptPattern) && @preg_match("~{$exceptPattern}~", $message) === 1) {
                        return;
                    }
                }

                $scope->setTag('app.name', Craft::$app->getSystemName());

                if (!empty($category)) {
                    $scope->setTag('category', $category);
                }

                if ($user && !$this->anonymous) {
                    $scope->setUser([
                        'email'      => $user->email,
                        'id'         => $user->id,
                        'ip_address' => $request->getRemoteIP(),
                        'username'   => $user->username,
                        'Admin'      => $user->admin ? 'Yes' : 'No',
                        'Groups'     => $groups ? implode(', ', $groups) : null,
                    ]);
                }

                $scope->setExtras($this->getExtras($request));

                if ($message instanceof \Throwable) {
                    Sentry\captureException($message);
                } else {
                    if (!is_string($message)) {
                        $message = VarDumper::export($message);
                    }

                    Sentry\captureMessage($message, $this->getSeverityLevel($level));
                }
            });
        }
    }

    /**
     * @inheritdoc
     */
    public function setLevels($levels): void
    {
        if (is_array($levels)) {
            foreach ($levels as $key => $level) {
                if (!in_array($level, ['error', 'warning'])) {
                    unset($levels[$key]);
                }
            }
        }

        parent::setLevels($levels);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns client options to be passed when Sentry initializes.
     *
     * @return array
     */
    protected function getOptions(): array
    {
        $options = [
            'dsn'                  => $this->dsn ?: null,
            'release'              => $this->release ?: null,
            'environment'          => $this->environment ?: (App::env('CRAFT_ENVIRONMENT') ?: null),
            'context_lines'        => 10,
            'send_default_pii'     => !$this->anonymous,
            'default_integrations' => true,

            'integrations' => function (array $integrations): array {
                return self::getIntegrations($integrations);
            },
        ];

        if (version_compare(Craft::$app->getVersion(), '3.7', '>=')) {
            $options['http_proxy'] = Craft::$app->getConfig()->getGeneral()->httpProxy;
        }

        unset($this->options['dsn']);
        unset($this->options['release']);
        unset($this->options['environment']);
        unset($this->options['send_default_pii']);
        unset($this->options['default_integrations']);
        unset($this->options['integrations']);

        return array_merge($options, $this->options);
    }

    /**
     * Returns extra context to be sent with each Sentry event.
     *
     * @param $request
     * @return array
     */
    protected function getExtras($request): array
    {
        /** @var \craft\console\Request|\craft\web\Request $request */

        $extras = [
            'App Name'      => Craft::$app->getSystemName(),
            'Craft Edition' => App::editionName(Craft::$app->getEdition()),
            'Craft Schema'  => Craft::$app->getInstalledSchemaVersion(),
            'Craft Version' => Craft::$app->getVersion(),
            'Dev Mode'      => Craft::$app->getConfig()->getGeneral()->devMode ? 'Yes' : 'No',
            'Environment'   => App::env('CRAFT_ENVIRONMENT') ?: null,
            'PHP Version'   => App::phpVersion(),
            'Request Type'  => $request->getIsConsoleRequest() ? 'Console' : ($request->getIsAjax() ? 'Ajax' : 'Web'),
            'Twig Version'  => TwigEnvironment::VERSION,
            'Yii Version'   => Yii::getVersion(),
        ];

        if ($request->getIsConsoleRequest()) {
            try {
                $scriptFile = $request->getScriptFile() . ' ';
            } catch(\Throwable $e) {
                $scriptFile = '';
            }

            $extras['Request Script'] = $scriptFile . implode(' ', $request->getParams());
        } else {
            $extras['Request Route'] = $request->getUrl();
        }

        try {
            $db = Craft::$app->getDb();
            $dbVersion = App::normalizeVersion($db->getSchema()->getServerVersion());

            if ($db->getIsMysql()) {
                $extras['Database Driver'] = 'MySQL ' . $dbVersion;
            } else {
                $extras['Database Driver'] = 'PostgreSQL ' . $dbVersion;
            }
        } catch (\Throwable $e) {}

        try {
            $imageService = Craft::$app->getImages();
            $imageVersion = $imageService->getVersion();

            if ($imageService->getIsGd()) {
                $extras['Image Driver'] = 'GD ' . $imageVersion;
            } else {
                $extras['Image Driver'] = 'Imagick ' . $imageVersion;
            }
        } catch (\Throwable $e) {}

        return $extras;
    }

    /**
     * Returns Sentry Integrations to be loaded.
     *
     * @param Sentry\Integration\IntegrationInterface[] $integrations
     * @return Sentry\Integration\IntegrationInterface[]
     */
    protected function getIntegrations(array $integrations): array
    {
        $integrations[] = new CraftIntegration();

        // Do not override Craft error and exception handlers
        return array_filter($integrations, static function (\Sentry\Integration\IntegrationInterface $integration): bool {
            return !$integration instanceof \Sentry\Integration\AbstractErrorListenerIntegration;
        });
    }

    /**
     * Translates log level to Sentry Severity level.
     *
     * @param int $level
     * @return Severity
     */
    protected function getSeverityLevel(int $level): Severity
    {
        switch($level) {
            case Logger::LEVEL_PROFILE_END:
            case Logger::LEVEL_PROFILE_BEGIN:
            case Logger::LEVEL_PROFILE:
            case Logger::LEVEL_TRACE:
                return Severity::debug();

            case Logger::LEVEL_INFO:
                return Severity::info();

            case Logger::LEVEL_WARNING:
                return Severity::warning();

            case Logger::LEVEL_ERROR:
                return Severity::error();

            default:
                throw new \UnexpectedValueException("An unsupported log level \"{$level}\" given.");
        }
    }
}
