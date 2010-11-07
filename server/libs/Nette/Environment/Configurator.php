<?php

/**
 * This file is part of the Nette Framework.
 *
 * Copyright (c) 2004, 2010 David Grudl (http://davidgrudl.com)
 *
 * This source file is subject to the "Nette license", and/or
 * GPL license. For more information please see http://nette.org
 * @package Nette
 */



/**
 * Environment helper.
 *
 * @author     David Grudl
 */
class Configurator extends Object
{
	/** @var string */
	public $defaultConfigFile = '%appDir%/config.ini';

	/** @var array */
	public $defaultServices = array(
		'Nette\\Application\\Application' => array(__CLASS__, 'createApplication'),
		'Nette\\Web\\HttpContext' => 'HttpContext',
		'Nette\\Web\\IHttpRequest' => 'HttpRequest',
		'Nette\\Web\\IHttpResponse' => 'HttpResponse',
		'Nette\\Web\\IUser' => 'User',
		'Nette\\Caching\\ICacheStorage' => array(__CLASS__, 'createCacheStorage'),
		'Nette\\Caching\\ICacheJournal' => array(__CLASS__, 'createCacheJournal'),
		'Nette\\Web\\Session' => 'Session',
		'Nette\\Loaders\\RobotLoader' => array(__CLASS__, 'createRobotLoader'),
	);



	/**
	 * Detect environment mode.
	 * @param  string mode name
	 * @return bool
	 */
	public function detect($name)
	{
		switch ($name) {
		case 'environment':
			// environment name autodetection
			if ($this->detect('console')) {
				return Environment::CONSOLE;

			} else {
				return Environment::getMode('production') ? Environment::PRODUCTION : Environment::DEVELOPMENT;
			}

		case 'production':
			// detects production mode by server IP address
			if (PHP_SAPI === 'cli') {
				return FALSE;

			} elseif (isset($_SERVER['SERVER_ADDR']) || isset($_SERVER['LOCAL_ADDR'])) {
				$addrs = array();
				if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) { // proxy server detected
					$addrs = preg_split('#,\s*#', $_SERVER['HTTP_X_FORWARDED_FOR']);
				}
				if (isset($_SERVER['REMOTE_ADDR'])) {
					$addrs[] = $_SERVER['REMOTE_ADDR'];
				}
				$addrs[] = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : $_SERVER['LOCAL_ADDR'];
				foreach ($addrs as $addr) {
					$oct = explode('.', $addr);
					// 10.0.0.0/8   Private network
					// 127.0.0.0/8  Loopback
					// 169.254.0.0/16 & ::1  Link-Local
					// 172.16.0.0/12  Private network
					// 192.168.0.0/16  Private network
					if ($addr !== '::1' && (count($oct) !== 4 || ($oct[0] !== '10' && $oct[0] !== '127' && ($oct[0] !== '172' || $oct[1] < 16 || $oct[1] > 31)
						&& ($oct[0] !== '169' || $oct[1] !== '254') && ($oct[0] !== '192' || $oct[1] !== '168')))) {
						return TRUE;
					}
				}
				return FALSE;

			} else {
				return TRUE;
			}

		case 'console':
			return PHP_SAPI === 'cli';

		default:
			// unknown mode
			return NULL;
		}
	}



	/**
	 * Loads global configuration from file and process it.
	 * @param  string|Config  file name or Config object
	 * @return Config
	 */
	public function loadConfig($file)
	{
		$name = Environment::getName();

		if ($file instanceof Config) {
			$config = $file;
			$file = NULL;

		} else {
			if ($file === NULL) {
				$file = $this->defaultConfigFile;
			}
			$file = Environment::expand($file);
			$config = Config::fromFile($file, $name);
		}

		// process environment variables
		if ($config->variable instanceof Config) {
			foreach ($config->variable as $key => $value) {
				Environment::setVariable($key, $value);
			}
		}

		// expand variables
		$iterator = new RecursiveIteratorIterator($config);
		foreach ($iterator as $key => $value) {
			$tmp = $iterator->getDepth() ? $iterator->getSubIterator($iterator->getDepth() - 1)->current() : $config;
			$tmp[$key] = Environment::expand($value);
		}

		// process services
		$runServices = array();
		$context = Environment::getContext();
		if ($config->service instanceof Config) {
			foreach ($config->service as $key => $value) {
				$key = strtr($key, '-', '\\'); // limited INI chars
				if (is_string($value)) {
					$context->removeService($key);
					$context->addService($key, $value);
				} else {
					if ($value->factory) {
						$context->removeService($key);
						$context->addService($key, $value->factory, isset($value->singleton) ? $value->singleton : TRUE, (array) $value->option);
					} elseif (isset($this->defaultServices[$key])) {
						$context->removeService($key);
						$context->addService($key, $this->defaultServices[$key], isset($value->singleton) ? $value->singleton : TRUE, (array) $value->option);
					}
					if ($value->run) {
						$runServices[] = $key;
					}
				}
			}
		}

		// process ini settings
		if (!$config->php) { // backcompatibility
			$config->php = $config->set;
			unset($config->set);
		}

		if ($config->php instanceof Config) {
			if (PATH_SEPARATOR !== ';' && isset($config->php->include_path)) {
				$config->php->include_path = str_replace(';', PATH_SEPARATOR, $config->php->include_path);
			}

			foreach (clone $config->php as $key => $value) { // flatten INI dots
				if ($value instanceof Config) {
					unset($config->php->$key);
					foreach ($value as $k => $v) {
						$config->php->{"$key.$k"} = $v;
					}
				}
			}

			foreach ($config->php as $key => $value) {
				$key = strtr($key, '-', '.'); // backcompatibility

				if (!is_scalar($value)) {
					throw new InvalidStateException("Configuration value for directive '$key' is not scalar.");
				}

				if ($key === 'date.timezone') { // PHP bug #47466
					date_default_timezone_set($value);
				}

				if (function_exists('ini_set')) {
					ini_set($key, $value);
				} else {
					switch ($key) {
					case 'include_path':
						set_include_path($value);
						break;
					case 'iconv.internal_encoding':
						iconv_set_encoding('internal_encoding', $value);
						break;
					case 'mbstring.internal_encoding':
						mb_internal_encoding($value);
						break;
					case 'date.timezone':
						date_default_timezone_set($value);
						break;
					case 'error_reporting':
						error_reporting($value);
						break;
					case 'ignore_user_abort':
						ignore_user_abort($value);
						break;
					case 'max_execution_time':
						set_time_limit($value);
						break;
					default:
						if (ini_get($key) != $value) { // intentionally ==
							throw new NotSupportedException('Required function ini_set() is disabled.');
						}
					}
				}
			}
		}

		// define constants
		if ($config->const instanceof Config) {
			foreach ($config->const as $key => $value) {
				define($key, $value);
			}
		}

		// set modes
		if (isset($config->mode)) {
			foreach($config->mode as $mode => $state) {
				Environment::setMode($mode, $state);
			}
		}

		// auto-start services
		foreach ($runServices as $name) {
			$context->getService($name);
		}

		return $config;
	}



	/********************* service factories ****************d*g**/



	/**
	 * Get initial instance of context.
	 * @return IContext
	 */
	public function createContext()
	{
		$context = new Context;
		foreach ($this->defaultServices as $name => $service) {
			$context->addService($name, $service);
		}
		return $context;
	}



	/**
	 * @return Application
	 */
	public static function createApplication()
	{
		if (Environment::getVariable('baseUri', NULL) === NULL) {
			Environment::setVariable('baseUri', Environment::getHttpRequest()->getUri()->getBasePath());
		}

		$context = clone Environment::getContext();
		$context->addService('Nette\\Application\\IRouter', 'MultiRouter');

		if (!$context->hasService('Nette\\Application\\IPresenterLoader')) {
			$context->addService('Nette\\Application\\IPresenterLoader', callback(create_function('', '
				return new PresenterLoader(Environment::getVariable(\'appDir\'));
			')));
		}

		$application = new Application;
		$application->setContext($context);
		$application->catchExceptions = Environment::isProduction();
		return $application;
	}



	/**
	 * @return ICacheStorage
	 */
	public static function createCacheStorage()
	{
		$context = new Context;
		$context->addService('Nette\\Caching\\ICacheJournal', array(__CLASS__, 'createCacheJournal'));
		$dir = Environment::getVariable('tempDir') . '/cache';
		umask(0000);
		@mkdir($dir, 0777); // @ - directory may exists
		return new FileStorage($dir, $context);
	}



	/**
	 * @return ICacheJournal
	 */
	public static function createCacheJournal()
	{
		/*if (SqliteJournal::isAvailable()) {
			return new SqliteJournal(Environment::getVariable('tempDir') . '/cache/cachejournal.db');
		} else*/ {
			return new FileJournal(Environment::getVariable('tempDir') . '/cache');
		}
	}



	/**
	 * @return RobotLoader
	 */
	public static function createRobotLoader($options)
	{
		$loader = new RobotLoader;
		$loader->autoRebuild = isset($options['autoRebuild']) ? $options['autoRebuild'] : !Environment::isProduction();
		$loader->setCacheStorage(Environment::getService('Nette\\Caching\\ICacheStorage'));
		if (isset($options['directory'])) {
			$loader->addDirectory($options['directory']);
		} else {
			foreach (array('appDir', 'libsDir') as $var) {
				if ($dir = Environment::getVariable($var, NULL)) {
					$loader->addDirectory($dir);
				}
			}
		}
		$loader->register();
		return $loader;
	}

}
