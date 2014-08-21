<?php

namespace m8rge\OCF;

use phpDocumentor\Reflection\DocBlock;

abstract class OCF
{
    /**
     * no error
     */
    const OCF_SUCCESS = 0;

    /**
     * generic error
     */
    const OCF_ERR_GENERIC = 1;

    /**
     * wrong console arguments. used.
     */
    const OCF_ERR_ARGS = 2;

    /**
     * action not implemented. used.
     */
    const OCF_ERR_UNIMPLEMENTED = 3;

    /**
     * permission error
     */
    const OCF_ERR_PERM = 4;

    /**
     * required component isn't installed. used.
     */
    const OCF_ERR_INSTALLED = 5;

    /**
     * wrong resource configuration. used.
     */
    const OCF_ERR_CONFIGURED = 6;

    /**
     * resource not running. May returned by monitor action
     */
    const OCF_NOT_RUNNING = 7;

    // Non-standard values
//    const OCF_RUNNING_MASTER = 8;
//    const OCF_FAILED_MASTER = 9;

    protected $version = '0.1';
    protected $language = 'en';

    /**
     * Sentry dsn url
     * @var string
     */
    public $sentryDSN = '';

    /**
     * @var \Raven_Client
     */
    protected $ravenClient;

    public function initProperties()
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($properties as $property) {
            if (isset($_ENV['OCF_RESKEY_' . $property->name])) {
                $this->{$property->name} = $_ENV['OCF_RESKEY_' . $property->name];
            }
        }
    }

    /**
     * @return bool
     */
    public function validateProperties()
    {
        // validating sentry dsn
        try {
            new \Raven_Client($this->sentryDSN);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        return true;
    }

    public function validateRequirements()
    {
        return true;
    }

    public function initSentry()
    {
        if ($this->sentryDSN) {
            $this->ravenClient = new \Raven_Client($this->sentryDSN);

            $error_handler = new \Raven_ErrorHandler($this->ravenClient);
            $error_handler->registerExceptionHandler();
            $error_handler->registerErrorHandler();
            $error_handler->registerShutdownFunction();
        }
    }

    /**
     * @param string $method
     */
    public function run($method)
    {
        $this->initProperties();
        if (!$this->validateProperties()) {
            exit (self::OCF_ERR_CONFIGURED);
        }
        if (!$this->validateRequirements()) {
            exit (self::OCF_ERR_INSTALLED);
        }
        $this->initSentry();

        $method = 'action-' . $method;
        $method = $this->convertToCamelCase($method);
        if (method_exists($this, $method)) {
            $returnCode = $this->$method();
            exit($returnCode);
        } else {
            exit(self::OCF_ERR_UNIMPLEMENTED);
        }
    }

    /**
     * @timeout 10
     * @return int
     */
    public function actionStart()
    {
        return self::OCF_ERR_UNIMPLEMENTED;
    }

    /**
     * @timeout 10
     * @return int
     */
    public function actionStop()
    {
        return self::OCF_ERR_UNIMPLEMENTED;
    }

    /**
     * @timeout 10
     * @interval 10
     * @return int
     */
    public function actionMonitor()
    {
        return self::OCF_ERR_UNIMPLEMENTED;
    }

    /**
     * @timeout 5
     * @return int
     */
    public function actionValidateAll()
    {
        // validation perform on every run before any action
        // reaching this point means everything is correct
        return self::OCF_SUCCESS;
    }

    public function actionMetaData()
    {
        $reflection = new \ReflectionClass($this);
        $classPhpDoc = new DocBlock($reflection->getDocComment());

        $ra = new \SimpleXMLElement('<!DOCTYPE resource-agent SYSTEM "ra-api-1.dtd"><resource-agent />');
        $ra->addAttribute('name', $reflection->getShortName());
        $ra->addAttribute('version', $this->version);
        $ra->addChild('version', $this->version);
        $this->setDescriptions($classPhpDoc, $ra);

        if ($properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC)) {
            $parameters = $ra->addChild('parameters');
            foreach ($properties as $property) {
                $propertyPhpDoc = new DocBlock($property->getDocComment());

                $parameter = $parameters->addChild('parameter');
                $parameter->addAttribute('name', $property->name);
                $unique = $this->getTag($propertyPhpDoc, 'unique');
                $parameter->addAttribute('unique', json_decode($unique) ? '1' : '0');
                $parameter->addAttribute('required', $property->getValue($this) === null ? '1' : '0');

                $this->setDescriptions($propertyPhpDoc, $parameter);

                if ($tags = $propertyPhpDoc->getTagsByName('var')) {
                    /** @var DocBlock\Tag\VarTag $tag */
                    $tag = reset($tags);
                    if ($tag instanceof DocBlock\Tag\VarTag) {
                        $content = $parameter->addChild('content');
                        $content->addAttribute('type', $this->convertToOcfType($tag->getType()));
                        if ($property->getValue($this)) {
                            $content->addAttribute('default', $property->getValue($this));
                        }
                    }
                }
            }
        }

        $actions = $ra->addChild('actions');
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            $declaringClass = $method->getDeclaringClass();
            if (($declaringClass->name != __CLASS__ || $method->name == 'actionValidateAll') &&
                strpos($method->name, 'action') === 0
            ) {
                $action = $actions->addChild('action');
                $actionName = str_replace('action', '', $method->name);
                $actionName[0] = strtolower($actionName[0]);
                $actionName = $this->convertFromCamelCase($actionName);
                $action->addAttribute('name', $actionName);

                $actionPhpDoc = new DocBlock($method);
                if ($timeout = $this->getTag($actionPhpDoc, 'timeout')) {
                    $action->addAttribute('timeout', $timeout);
                }
                if ($interval = $this->getTag($actionPhpDoc, 'interval')) {
                    $action->addAttribute('interval', $interval);
                }
            }
        }

        echo $ra->asXML();
    }

    /**
     * @param $string
     * @return string|null
     */
    protected function convertToCamelCase($string) //todo: extract to helper
    {
        return preg_replace_callback(
            '/-(\w)/',
            function ($matches) {
                return strtoupper($matches[1]);
            },
            strtolower($string)
        );
    }

    /**
     * @param $string
     * @return string
     */
    protected function convertFromCamelCase($string) //todo: extract to helper
    {
        return preg_replace_callback(
            '/([A-Z])/',
            function ($matches) {
                return '-' . strtolower($matches[1]);
            },
            $string
        );
    }

    /**
     * @param string $phpDocType
     * @return string
     */
    protected function convertToOcfType($phpDocType) //todo: extract to helper
    {
        $type = 'string';

        if ($phpDocType == 'int' || $phpDocType == 'integer') {
            $type = 'integer';
        } elseif ($phpDocType == 'bool' || $phpDocType == 'boolean') {
            $type = 'boolean';
        }

        return $type;
    }

    /**
     * @param DocBlock $docBlock
     * @param string $name
     * @return null|string
     */
    protected function getTag($docBlock, $name) //todo: extract to extension of DocBlock
    {
        $tags = $docBlock->getTagsByName($name);
        if ($tags) {
            /** @var DocBlock\Tag $tag */
            $tag = reset($tags);
            return $tag->getContent();
        }

        return null;
    }


    /**
     * @param DocBlock $docBlock
     * @param \SimpleXMLElement $xmlElement
     */
    protected function setDescriptions($docBlock, $xmlElement) //todo: extract to extension of DocBlock
    {
        if ($docBlock->getShortDescription()) {
            $shortDesc = $xmlElement->addChild('shortdesc', $docBlock->getShortDescription());
            $shortDesc->addAttribute('lang', $this->language);
        }
        if ($docBlock->getLongDescription()->getContents()) {
            $longDesc = $xmlElement->addChild('longdesc', $docBlock->getLongDescription()->getContents());
            $longDesc->addAttribute('lang', $this->language);
        }
    }

    /**
     * @param string $name
     * @param string $value
     * @return int
     */
    protected function setAttribute($name, $value)
    {
        $command = "attrd_updater -n " . escapeshellarg($name) . ' -v ' . escapeshellarg($value);
        $exitCode = $this->execWithLogging($command);
        exec($command, $output, $exitCode);

        return $exitCode ? self::OCF_ERR_GENERIC : self::OCF_SUCCESS;
    }

    /**
     * @param string $name
     * @return int
     */
    protected function removeAttribute($name)
    {
        $command = "attrd_updater -D -n " . escapeshellarg($name);
        $exitCode = $this->execWithLogging($command);
        exec($command, $output, $exitCode);

        return $exitCode ? self::OCF_ERR_GENERIC : self::OCF_SUCCESS;
    }

    /**
     * @param string $command
     * @return int
     */
    public function execWithLogging($command)
    {
        exec($command, $output, $exitCode);
        if ($exitCode) {
            $executable = explode(' ', $command, 2);
            $executable = reset($executable);
            $this->ravenClient->extra_context(['command' => $command, 'output' => $output, 'exitCode' => $exitCode]);
            $this->ravenClient->captureException(new \Exception("$executable executed with error"));
            return $exitCode;
        }

        return $exitCode;
    }
}