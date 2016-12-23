<?php

$app['debug'] = false;
$app['charset'] = "iso-8859-1";

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
            'driver'    => 'pdo_mysql',
	        'host'      => 'localhost',
	        'dbname'    => 'buckeye',
	        'user'      => 'root',
	        'password'  => '',
        ),
));

$app->register(new Silex\Provider\SwiftmailerServiceProvider());

$app['swiftmailer.options'] = array(
    'host' => 'mail.ambarnet.com.br',
    'port' => '25',
    'username' => 'contato@ambarnet.com.br',
    'password' => '',
    'encryption' => null,
    'auth_mode' => null
);

$app['application_mail'] = "contato@ambarnet.com.br";

$app->register(new Silex\Provider\SessionServiceProvider());

$app->register(new Silex\Provider\SecurityServiceProvider(), array(
        'security.firewalls' => array(
            'login_path' => array(
                'pattern' => '^/login$',
                'anonymous' => true
            ),
            'recover_password_path' => array(
                'pattern' => '^/recuperar-senha$',
                'anonymous' => true
            ),
            'default' => array(
                'pattern' => '^/.*$',
                'anonymous' => false,
                'form' => array(
                    'login_path' => '/login',
                    'check_path' => '/login_check',
                    'always_use_default_target_path' => true,
                    'default_target_path' => '/login/redirect'
                ),
                'logout' => array(
                    'logout_path' => '/logout',
                    'invalidate_session' => false
                ),
                'users' => $app->share(function($app) { 
                    return new App\User\UserProvider($app['db']); 
                }),
            )
        ),
        'security.access_rules' => array(
            array('^/login$', 'IS_AUTHENTICATED_ANONYMOUSLY'),
            array('^/recuperar-senha$', 'IS_AUTHENTICATED_ANONYMOUSLY'),
            array('^/admin', 'ROLE_ADMIN'),
            array('^.*$', array('ROLE_ADMIN', 'ROLE_OPERADOR'))
        )
    ));

$app->register(new Silex\Provider\RememberMeServiceProvider());

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__ . '/views',
    'twig.options'=>array(
        'cache'     => __DIR__.'/../cache',
    ),
    'twig.form.templates' => array(
        'form_div_layout.html.twig', 
        'theme/form_div_layout.twig'
    ),
));

$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__.'/../logs/buckeye-admin.log',
    'monolog.level' => Monolog\Logger::DEBUG,
    'monolog.name' => 'buckeye-admin'
));

$app->register(new Silex\Provider\FormServiceProvider());

$app->register(new Silex\Provider\TranslationServiceProvider(), array(
	'locale' => 'sr_Latn',
    'translator.domains' => array(),
));

return $app;
