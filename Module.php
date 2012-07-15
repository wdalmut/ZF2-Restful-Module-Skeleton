<?php

namespace Main;

use Zend\Mvc\MvcEvent,
	\Zend\ModuleManager\ModuleManager;

/**
 *
 */
class Module
{
	/**
	 * @param ModuleManager $moduleManager
	 */
	public function init(ModuleManager $moduleManager)
	{
		$sharedEvents = $moduleManager->getEventManager()->getSharedManager();

		$sharedEvents->attach('Zend\Mvc\Controller\AbstractRestfulController', MvcEvent::EVENT_DISPATCH, array($this, 'postProcess'), -100);
		$sharedEvents->attach('Zend\Mvc\Application', MvcEvent::EVENT_DISPATCH_ERROR, array($this, 'errorProcess'), 999);
	}

	/**
	 * return array
	 */
	public function getAutoloaderConfig()
	{
		return array(
			'Zend\Loader\ClassMapAutoloader' => array(
				__DIR__ . '/autoload_classmap.php',
			),
			'Zend\Loader\StandardAutoloader' => array(
				'namespaces' => array(
					__NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
				),
			),
		);
	}

	/**
	 * @return array
	 */
	public function getConfig()
	{
		return include __DIR__ . '/configs/module.config.php';
	}

	/**
	 * @param MvcEvent $e
	 * @return null|\Zend\Http\PhpEnvironment\Response
	 */
	public function postProcess(MvcEvent $e)
	{
		$routeMatch = $e->getRouteMatch();
		$formatter  = $routeMatch->getParam('formatter', false);

		/** @var \Zend\Di\Di $di */
		$di = $e->getTarget()->getServiceLocator()->get('di');

		if ($formatter !== false) {
			/** @var PostProcessor\AbstractPostProcessor $postProcessor */
			$postProcessor = $di->get($formatter . '-pp', array(
				'vars'     => (is_array($e->getResult()) ? $e->getResult() : $e->getResult()->getVariables()),
				'response' => $e->getResponse()
			));

			$postProcessor->process();

			return $postProcessor->getResponse();
		}

		return null;
	}

	/**
	 * @param MvcEvent $e
	 * @return null|\Zend\Http\PhpEnvironment\Response
	 */
	public function errorProcess(MvcEvent $e)
	{
		/** @var \Zend\Di\Di $di */
		$di = $e->getApplication()->getServiceManager()->get('di');

		$eventParams = $e->getParams();

		/** @var array $configuration */
		$configuration = $e->getApplication()->getConfiguration();

		$vars = array();
		if (isset($eventParams['exception'])) {
			/** @var \Exception $exception */
			$exception = $eventParams['exception'];
	
			if ($configuration['errors']['show_exceptions']['message']) {
				$vars['error-message'] = $exception->getMessage();
			}
			if ($configuration['errors']['show_exceptions']['trace']) {
				$vars['error-trace'] = $exception->getTrace();
			}
		}
		
		if (empty($vars)) {
			$vars['error'] = 'Something went wrong';
		}

		/** @var PostProcessor\AbstractPostProcessor $postProcessor */
		$postProcessor = $di->get($configuration['errors']['post_processor'], array(
			'vars'     => $vars,
			'response' => $e->getResponse()
		));

		$postProcessor->process();

		if (
			$eventParams['error'] === \Zend\Mvc\Application::ERROR_CONTROLLER_NOT_FOUND ||
			$eventParams['error'] === \Zend\Mvc\Application::ERROR_ROUTER_NO_MATCH
		) {
			$e->getResponse()->setStatusCode(\Zend\Http\PhpEnvironment\Response::STATUS_CODE_404);
		} else {
			$e->getResponse()->setStatusCode(\Zend\Http\PhpEnvironment\Response::STATUS_CODE_500);
		}

		$e->stopPropagation();

		return $postProcessor->getResponse();
	}
}
