<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Silex\Provider\FormServiceProvider;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Security\Core\User\User as AdvancedUser;

$app->before(function () use ($app) {
    $app['translator']->addLoader('xlf', new Symfony\Component\Translation\Loader\XliffFileLoader());
    $app['translator']->addResource('xlf', __DIR__.'/vendor/symfony/validator/Symfony/Component/Validator/Resources/translations/validators/validators.sr_Latn.xlf', 'sr_Latn', 'validators');
});

$app->mount('/login/redirect', new App\Controller\LoginRedirect());

$app->get('/', function() use ($app) {
    return $app->redirect('/home');
});

$app->get('/home', function() use ($app) {

    $token = $app['security']->getToken();
    
    $sql = "SELECT o.id, o.created_at AS abertura, ip.id AS idItem, ip.quantidade, ip.defeito, ";
    $sql .= "sip.nome AS status_nome, p.produto FROM itens_pedido ip INNER JOIN status_item_pedido sip ON ip.status = sip.id ";
    $sql .= "INNER JOIN produtos p ON ip.id_produto = p.id INNER JOIN pedidos o ON o.id = ip.id_pedido WHERE o.id_operador = ? ";
    $sql .= "ORDER BY o.created_at DESC, ip.id DESC";

    $pedidos = $app['db']->fetchAll($sql, array((int)getUserId($app)));

    $orders = array();
    $idPedido = -1;

    foreach ($pedidos as $pedido) {
        if($idPedido == -1) 
            $idPedido = $pedido['id'];
        $idPedido = ($idPedido <> $pedido['id'])?$pedido['id']:$idPedido;
        $orders['aberto_por'][$idPedido]['itens'][] = array_intersect_key($pedido, array_flip(array('idItem', 'quantidade', 'defeito', 'status_nome', 'produto')));
        $orders['aberto_por'][$idPedido]['abertura'] = $pedido['abertura'];
    }

    if($idPedido == -1)
        $orders['aberto_por'] = array();
    else
        $idPedido = -1;

    $sql = "SELECT o.id, o.created_at AS abertura, ip.id AS idItem, ip.quantidade, ip.defeito, ";
    $sql .= "sip.nome AS status_nome, p.produto FROM itens_pedido ip INNER JOIN status_item_pedido sip ON ip.status = sip.id ";
    $sql .= "INNER JOIN produtos p ON ip.id_produto = p.id INNER JOIN pedidos o ON o.id = ip.id_pedido WHERE ip.recebido_por = ? ";
    $sql .= "ORDER BY o.created_at DESC, ip.id DESC";

    $pedidos = $app['db']->fetchAll($sql, array((int)getUserId($app)));

    foreach ($pedidos as $pedido) {
        if($idPedido == -1) 
            $idPedido = $pedido['id'];

        $idPedido = ($idPedido <> $pedido['id'])?$pedido['id']:$idPedido;
        $orders['recebido_por'][$idPedido]['itens'][] = array_intersect_key($pedido, array_flip(array('idItem', 'quantidade', 'defeito', 'status_nome', 'produto')));
        $orders['recebido_por'][$idPedido]['abertura'] = $pedido['abertura'];
    }

    if($idPedido == -1)
        $orders['recebido_por'] = array();
    else
        $idPedido = -1;

    $sql = "SELECT c.nome, c.email, o.id, o.created_at AS abertura, ip.id AS idItem, ip.quantidade, ip.defeito, ";
    $sql .= "sip.nome AS status_nome, p.produto FROM itens_pedido ip INNER JOIN status_item_pedido sip ON ip.status = sip.id ";
    $sql .= "INNER JOIN produtos p ON ip.id_produto = p.id INNER JOIN pedidos o ON o.id = ip.id_pedido INNER JOIN clientes c ON o.id_cliente = c.id WHERE sip.nome = ? ";
    $sql .= "ORDER BY o.created_at DESC, ip.id DESC";

    $pedidos = $app['db']->fetchAll($sql, array((string)"Aguardando atendimento"));

    foreach ($pedidos as $pedido) {
        if($idPedido == -1) 
            $idPedido = $pedido['id'];

        $idPedido = ($idPedido <> $pedido['id'])?$pedido['id']:$idPedido;
        $orders['aguardando_atendimento'][$idPedido]['itens'][] = array_intersect_key($pedido, array_flip(array('idItem', 'nome', 'email', 'quantidade', 'defeito', 'status_nome', 'produto')));
        $orders['aguardando_atendimento'][$idPedido]['abertura'] = $pedido['abertura'];
    }

    if($idPedido == -1)
        $orders['aguardando_atendimento'] = array();

    $nomeUsuario = $app['db']->fetchColumn("SELECT nome FROM usuarios WHERE login = ?", array((string)$token->getUser()->getUsername()), 0);

    return $app['twig']->render('home.html', array('pedidos' => $orders, 'nomeUsuario' => $nomeUsuario));

})
->bind('home');

$login = function(Request $request) use ($app) {
    return $app['twig']->render('login_.html', array(
        'error' => $app['security.last_error']($request),
        'last_username' => $app['session']->get('_security.last_username'),
    ));
};

$app->get('/login', $login);

$app->match('/novo-chamado', function(Request $request) use ($app) {

    $tipos_produto = $app['db']->fetchAll("SELECT * FROM tipo_produto");

    return $app['twig']->render('novo-chamado.html', array(
        'tipos_produto' => $tipos_produto
    ));

});

$app->post('/insere-item', function(Request $request) use ($app) {

    $idPedido = intval($request->get('id_pedido'));

    if(empty($idPedido)) { //se o pedido ainda não existir, eh criado um novo

        $idUsuario = getUserId($app);
        $idCliente = $app['db']->fetchColumn("SELECT id FROM clientes WHERE email = ?", array((string)"operador.sistema@ambarnet.com.br"), 0); //mandatory

        $app['db']->insert('pedidos', array('id_cliente' => $idCliente, 'id_operador' => $idUsuario));
        $idPedido = $app['db']->lastInsertId();

        $sql = "SELECT nome, email FROM usuarios WHERE id = ?";
        $usuario = $app['db']->fetchAssoc($sql, array(intval($idUsuario)));

        /*
        $message = \Swift_Message::newInstance();
        $message->setSubject(utf8_encode("Nova ordem de serviço aberta - Sistema de Abertura de Chamados - Ambar Technology"));
        $message->setFrom(array("contato@brigadeirogourmetdelicia.com.br"));
        $message->setTo(array($usuario['email'], $app['application_mail']));

        $message->setBody("Nova ordem de serviço aberta no Sistema de Abertura de Chamados!\r\n\r\nUsuário (Nome/E-mail): " . $usuario['nome'] . " / " . $usuario['email'] . "\r\nHora/Data:" . date("H:i:s") . " do dia " . date("d/m/Y") . "\r\nAberto a partir do equipamento identificado pelo IP: " . $app['request']->server->get('REMOTE_ADDR'));
        $app['monolog']->addDebug("E-mail: " . $usuario['email']);
        $app['mailer']->send($message);
        */
    }

    $produto = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('produto')));
    $descProduto = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('descricao-produto')));
    $numSerieProduto = trim($request->get('numero-serie-produto'));
    $modeloProduto = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('modelo-produto')));
    $tipoProduto = intval($request->get('tipo-produto'));

    $quantidade = $request->get('quantidade');
    $defeito = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('defeito')));

    //inserindo novo produto
    $app['db']->insert('produtos', array('produto' => $produto, 'descricao' => $descProduto, 'numero_serie' => $numSerieProduto, 'modelo' => $modeloProduto, 'tipo_produto' => $tipoProduto));
    $idProduto = $app['db']->lastInsertId(); //id do ultimo produto cadastrado

    $status = $app['db']->fetchColumn("SELECT id FROM status_item_pedido WHERE nome = ?", array((string)"Aguardando atendimento"), 0); //mandatory
    $app['db']->insert('itens_pedido', array('quantidade' => $quantidade, 'defeito' => $defeito, 'id_produto' => $idProduto, 'id_pedido' => $idPedido, 'status' => $status));

    return new Response($idPedido, 201);

});

$app->get('/itens-chamado/{idPedido}', function(Request $request, $idPedido) use ($app) {
    $sql = "SELECT ip.id AS cod_item, ip.quantidade, ip.valor, ip.defeito, ip.valor_maodeobra, ip.prazo_entrega, ip.garantia, ip.fatura, ip.recebido_por, ";
    $sql .= "sip.nome AS status_nome, p.produto, ip.created_at FROM itens_pedido ip INNER JOIN status_item_pedido sip ON ip.status = sip.id INNER JOIN produtos p ON ";
    $sql .= "ip.id_produto = p.id INNER JOIN pedidos o ON o.id = ip.id_pedido WHERE ip.id_pedido = ? AND o.id_operador = ? ORDER BY ip.created_at DESC";

    $itensPedido = $app['db']->fetchAll($sql, array((int)$idPedido, (int)getUserId($app)));

    return $app['twig']->render('itens-pedido.html', array(
        'itens_pedido' => $itensPedido,
    ));
});

$app->get('/visualizar-item/{id}', function(Request $request, $id) use ($app) {

    $sql = "SELECT ip.quantidade, IF(ip.valor IS NULL, '', ip.valor * ip.quantidade) AS valor_total_item, ";
    $sql .= "ip.defeito, IF(ip.valor_maodeobra IS NULL, '', ip.valor_maodeobra) AS maodeobra, ";
    $sql .= "ip.prazo_entrega, IF(ip.garantia IS NULL, '', ip.garantia) AS garantia, ";
    $sql .= "IF(ip.fatura IS NULL, '', ip.fatura) AS fatura, IF(ip.recebido_por IS NULL, '', ip.recebido_por) AS recebido_por, ";
    $sql .= "IF(ip.chamado IS NULL, '', ip.chamado) AS chamado, s.nome AS status, p.produto, tp.tipo AS tipo_produto ";
    $sql .= "FROM itens_pedido ip INNER JOIN status_item_pedido s ON ip.status = s.id INNER JOIN produtos p ON ip.id_produto = p.id ";
    $sql .= "INNER JOIN tipo_produto tp ON p.tipo_produto = tp.id INNER JOIN pedidos o ON o.id = ip.id_pedido WHERE ip.id = ? AND o.id_operador = ?";

    $item = $app['db']->fetchAssoc($sql, array((int)$id, (int)getUserId($app)));

    return $app['twig']->render('item-pedido.html', array(
        'item' => $item,
    ));

});

$app->get('/visualizar-chamado/{id}', function(Request $request, $id) use ($app) {

    $sql = "SELECT * FROM pedidos WHERE id = ?";
    $pedido = $app['db']->fetchAssoc($sql, array((int)$id));

    $sql = "SELECT ip.id AS id_item, ip.quantidade, ip.valor, ip.valor_maodeobra, (ip.quantidade * ip.valor + ip.valor_maodeobra) AS valor_total, ip.garantia, ip.fatura, ip.recebido_por, ip.chamado, ip.created_at, s.nome AS status, p.* FROM itens_pedido ip INNER JOIN produtos p ON ip.id_produto = p.id INNER JOIN status_item_pedido s ON ip.status = s.id INNER JOIN pedidos o ON o.id = ip.id_pedido WHERE ip.id_pedido = ? AND o.id_operador = ?";
    $itens_pedido = $app['db']->fetchAll($sql, array((int)$id, (int)getUserId($app)));

    return $app['twig']->render('pedido.html', array(
        'pedido_id' => $id,
        'itens_pedido' => $itens_pedido,
    ));
});

$app->match('/admin/registrar-usuario', function (Request $request) use ($app) {

    $sql = "SELECT id, nome_tipo FROM tipo_usuario ORDER BY id DESC";
    $tipos_usuario = $app['db']->fetchAll($sql);

    $tipos = array();
    foreach($tipos_usuario as $tipo_usuario)
        $tipos[$tipo_usuario['id']] = $tipo_usuario['nome_tipo'];

    $form = $app['form.factory']->createBuilder('form')
        ->add('nome')
        ->add('login')
        ->add('email', 'email')
        ->add('senha', 'password')
        ->add('tipo_usuario', ChoiceType::class, array(
            'choices' => $tipos
        ))
        ->getForm();

    #$form->handleRequest($request);
	if ($request->isMethod('POST')) {
		$form->bind($request);
    	if ($form->isValid()) {
        	$data = $form->getData();
			$user = new AdvancedUser($data['login'], $data['senha']);
			$encoder = $app['security.encoder_factory']->getEncoder($user);
			$encodedPassword = $encoder->encodePassword($data['senha'], $user->getSalt());
			$app['db']->insert('usuarios', array(
				'login' => $data['login'], 'senha' => $encodedPassword,
				'tipo_usuario' => $data['tipo_usuario'], 'nome' => $data['nome'], 'email' => $data['email']));
			
			$app['session']->getFlashBag()->add('message', 'Usuário registrado com sucesso!');
		}
    }

    // display the form
    return $app['twig']->render('form-bootstrap.html', array('form' => $form->createView(), 'titulo' => 'Criar novo usuário'));

});

$app->match('/admin/editar-usuario/{idUsuario}', function (Request $request, $idUsuario) use ($app) {

    $sql = "SELECT id, nome_tipo FROM tipo_usuario ORDER BY id DESC";
    $tipos_usuario = $app['db']->fetchAll($sql);

    $tipos = array();
    foreach($tipos_usuario as $tipo_usuario)
        $tipos[$tipo_usuario['id']] = $tipo_usuario['nome_tipo'];

    $sql = "SELECT nome, login, email, tipo_usuario FROM usuarios WHERE id = ?";
    $usuario = $app['db']->fetchAssoc($sql, array(intval($idUsuario)));

    $form = $app['form.factory']->createBuilder('form', $usuario)
        ->add('nome', 'text', array('read_only' => true))
        ->add('login', 'text', array('read_only' => true))
        ->add('email', 'email', array('read_only' => true))
        ->add('tipo_usuario', ChoiceType::class, array(
            'choices' => $tipos
        ))
        ->getForm();

    #$form->handleRequest($request);
    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();
            
            $app['db']->update('usuarios', array('tipo_usuario' => $data['tipo_usuario']), array('login' => $data['login']));
            
            $app['session']->getFlashBag()->add('message', 'Usuário alterado com sucesso!');
        }
    }

    // display the form
    return $app['twig']->render('form-bootstrap.html', array('form' => $form->createView(), 'titulo' => 'Editar usuário'));

});

$app->get('/admin/clientes', function() use ($app) {

    $sql = "SELECT id, nome, email, telefone, cidade, estado, cep FROM clientes ORDER BY nome ASC";
    $clientes = $app['db']->fetchAll($sql);

    return $app['twig']->render('clientes.html', array('clientes' => $clientes, 'titulo' => 'Clientes cadastrados'));

});

$app->get('/admin/excluir-usuario', function(Request $request) use ($app) {

    $id_usuario = $request->get('id_usuario');
    $app['db']->delete('usuarios', array('id' => $id_usuario));

    return new Response('Usuário excluído com sucesso!', 200);
});

$app->get('/admin/usuarios', function() use ($app) {

    $sql = "SELECT u.id, u.nome, u.login, t.nome_tipo AS tipo_usuario FROM usuarios u INNER JOIN tipo_usuario t ON u.tipo_usuario = t.id WHERE u.id <> ? ORDER BY u.id ASC";
    $usuarios = $app['db']->fetchAll($sql, array((int)getUserId($app)));

    return $app['twig']->render('usuarios.html', array('usuarios' => $usuarios, 'titulo' => 'Usuários cadastrados'));

});

$app->match('/recuperar-senha', function (Request $request) use ($app) {

    $form = $app['form.factory']->createBuilder('form')
        ->add('email', 'email')
        ->getForm();

    #$form->handleRequest($request);
    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();
            $sql = "SELECT * FROM usuarios WHERE email = ?";
            $cliente = $app['db']->fetchAssoc($sql, array((string)trim($data['email'])));

            if($cliente != null) {
                $random = random_password(8);

                $app['monolog']->addDebug("Nova senha: " . $random);

                $user = new AdvancedUser($data['email'], $random);
                $encoder = $app['security.encoder_factory']->getEncoder($user);
                $encodedPassword = $encoder->encodePassword($random, $user->getSalt());
                $app['db']->update('usuarios', array('senha' => $encodedPassword), array('email' => trim($data['email'])));
                
                $app['session']->getFlashBag()->add('message', 'Uma nova senha foi enviada para seu e-mail cadastrado!');
            } else
                $app['session']->getFlashBag()->add('error', 'Não foi encontrado nenhum usuário cadastrado com o e-mail ' . $data['email'] . '!');

        }
    }

    // display the form
    return $app['twig']->render('form-bootstrap-anon.html', array('form' => $form->createView()));
});

$app->match('/alterar-dados', function (Request $request) use ($app) {

    $sql = "SELECT id, nome_tipo FROM tipo_usuario ORDER BY id DESC";
    $tipos_usuario = $app['db']->fetchAll($sql);

    $tipos = array();
    foreach($tipos_usuario as $tipo_usuario)
        $tipos[$tipo_usuario['id']] = $tipo_usuario['nome_tipo'];

    $sql = "SELECT id, nome, email, login, tipo_usuario FROM usuarios WHERE id = ?";
    $usuario = $app['db']->fetchAssoc($sql, array((int)getUserId($app)));

    $form = $app['form.factory']->createBuilder('form', $usuario)
        ->add('id', 'hidden')
        ->add('nome')
        ->add('login', 'text', array('read_only' => true))
        ->add('senha_atual', 'password')
        ->add('nova_senha', 'password')
        ->add('confirmar_senha', 'password')
        ->add('email', 'email')
        ->getForm();

    #$form->handleRequest($request);
    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();

            $user = new AdvancedUser(trim($usuario['login']), trim($data['senha_atual']));
            $encoder = $app['security.encoder_factory']->getEncoder($user);
            $encodedPassword = $encoder->encodePassword(trim($data['senha_atual']), $user->getSalt());

            $sql = "SELECT * FROM usuarios WHERE senha = ? AND id = ?";
            $numRows = $app['db']->executeQuery($sql, array((string)$encodedPassword, (int)getUserId($app)))->rowCount();

            $mensagem = "";

            if($numRows != 1)
                $mensagem = 'A senha atual não confere!';    
            else if(trim($data['confirmar_senha']) != trim($data['nova_senha']))
                $mensagem = 'A nova senha é diferente da confirmação da nova senha!';

            if($mensagem <> "") {
                $app['session']->getFlashBag()->add('error', $mensagem);
                return $app['twig']->render('form-bootstrap.html', array('form' => $form->createView(), 'titulo' => 'Edite seus dados'));
            }

            $newUser = new AdvancedUser(trim($usuario['login']), trim($data['nova_senha']));
            $encoderNewUser = $app['security.encoder_factory']->getEncoder($newUser);
            $newEncodedPassword = $encoder->encodePassword(trim($data['nova_senha']), $newUser->getSalt());

            $app['db']->update('usuarios', array(
                'email' => trim($data['email']), 'senha' => $newEncodedPassword,
                'nome' => trim($data['nome'])), array('id' => trim($data['id']), 'senha' => $encodedPassword));

            $message = \Swift_Message::newInstance();
            $message->setSubject("Seus dados foram alterados - Sistema de Abertura de Chamados - Ambar Technology");
            $message->setFrom(array("contato@brigadeirogourmetdelicia.com.br"));
            $message->setTo(array($usuario['email']));

            $message->setBody("Seus dados foram alterados às " . date("H:i:s") . " do dia " . date("d/m/Y") . " a partir do equipamento identificado pelo IP " . $app['request']->server->get('REMOTE_ADDR'));
            $app['monolog']->addDebug("E-mail: " . $usuario['email']);
            $app['mailer']->send($message);
            
            $app['session']->getFlashBag()->add('message', 'Seus dados foram atualizados com sucesso!');

        }
    }

    // display the form
    return $app['twig']->render('form-bootstrap.html', array('form' => $form->createView(), 'titulo' => 'Edite seus dados'));
});

$app->match('/novo-tipo-produto', function (Request $request) use ($app) {

    $form = $app['form.factory']->createBuilder('form')
        ->add('tipo')
        ->add('descricao', 'textarea')
        ->getForm();

    #$form->handleRequest($request);
    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();

            $app['db']->insert('tipo_produto', array(
                'tipo' => trim($data['tipo']), 'descricao' => trim($data['descricao'])));
            
            $app['session']->getFlashBag()->add('message', 'O tipo de produto "' . $data['tipo'] . '" foi criado com sucesso!');
        }
    }

    // display the form
    return $app['twig']->render('form-bootstrap.html', array('form' => $form->createView(), 'titulo' => 'Criar novo tipo de produto'));
});

$app->post('/atualiza-item-pedido', function (Request $request) use ($app) {

    $prazo_entrega = trim($request->get('prazo_entrega'));
    $garantia = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('garantia')));
    $fatura = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('fatura')));
    $chamado = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('chamado')));
    $observacoes = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('observacoes')));
    $status = $request->get('status');
    $recebido_por = intval($request->get('recebido_por'));
    $valor_maodeobra = str_replace(",", ".", trim($request->get('valor_maoobra')));
    $valor = str_replace(",", ".", trim($request->get('valor')));
    $idItem = trim($request->get('id_item_pedido'));

    $fieldsToUpdate = array('prazo_entrega' => $prazo_entrega, 'garantia' => $garantia,
                        'fatura' => $fatura, 'chamado' => $chamado, 'observacoes' => $observacoes,
                        'status' => $status, 'valor_maodeobra' => $valor_maodeobra, 'valor' => $valor);

    if($recebido_por == "")
        $fieldsToUpdate['recebido_por'] = getUserId($app);

    $valor_maodeobra = (empty($valor_maodeobra))?0.00:$valor_maodeobra;
    $valor = (empty($valor))?0.00:$valor;

    $app['db']->update('itens_pedido', $fieldsToUpdate, array('id' => $idItem));

    return new Response('Item #' . $idItem . ' atualizado com sucesso!', 200);

});

$app->get('/visualizar-item-full/{id}', function(Request $request, $id) use ($app) {

    $sql = "SELECT ip.id AS idPedido, ip.quantidade, IF(ip.valor IS NULL, '', ip.valor * ip.quantidade) AS valor_total_item, ";
    $sql = $sql .= "ip.created_at, ip.defeito, IF(ip.valor_maodeobra IS NULL, '', ip.valor_maodeobra) AS maodeobra, ip.prazo_entrega, ";
    $sql .= "IF(ip.garantia IS NULL, '', ip.garantia) AS garantia, ip.observacoes, IF(ip.fatura IS NULL, '', ip.fatura) AS fatura, ip.recebido_por, ";
    $sql .= "IF(ip.chamado IS NULL, '', ip.chamado) AS chamado, s.nome AS status, s.id AS idStatus, p.produto, p.descricao AS descProd, ";
    $sql .= "p.numero_serie AS prodNSerie, p.modelo AS prodMod, tp.tipo AS tipo_produto, tp.id AS idTipoProd FROM itens_pedido ip ";
    $sql .= "INNER JOIN status_item_pedido s ON ip.status = s.id INNER JOIN produtos p ON ip.id_produto = p.id ";
    $sql .= "INNER JOIN tipo_produto tp ON p.tipo_produto = tp.id INNER JOIN pedidos o ON o.id = ip.id_pedido WHERE ip.id = ?";

    $item = $app['db']->fetchAssoc($sql, array((int)$id));

    $tipos_produto = $app['db']->fetchAll("SELECT * FROM tipo_produto");
    $statuses = $app['db']->fetchAll("SELECT * FROM status_item_pedido");
    $usuarios = $app['db']->fetchAll("SELECT * FROM usuarios");

    return $app['twig']->render('modal.html', array(
        'item' => $item,
        'tipos_produto' => $tipos_produto,
        'statuses' => $statuses,
        'usuarios' => $usuarios
    ));

});

$app->match('/editar-tipo-produto/{idTipo}', function (Request $request, $idTipo) use ($app) {

    $sql = "SELECT id, tipo, descricao FROM tipo_produto WHERE id = ?";
    $tipo = $app['db']->fetchAssoc($sql, array((int)$idTipo));

    $form = $app['form.factory']->createBuilder('form', $tipo)
        ->add('id', 'hidden')
        ->add('tipo')
        ->add('descricao', 'textarea')
        ->getForm();

    #$form->handleRequest($request);
    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();

            $app['db']->update('tipo_produto', array(
                'tipo' => trim($data['tipo']), 'descricao' => trim($data['descricao'])), array('id' => $data['id']));
            
            $app['session']->getFlashBag()->add('message', 'O tipo de produto #' . $data['id'] . ' foi alterado com sucesso!');
        }
    }

    // display the form
    return $app['twig']->render('form-bootstrap.html', array('form' => $form->createView(), 'titulo' => 'Editar tipo de produto'));
});

$app->get('/tipos-produtos', function() use ($app) {

    $sql = "SELECT id, tipo, descricao FROM tipo_produto ORDER BY id ASC";
    $tipos_produtos = $app['db']->fetchAll($sql);

    return $app['twig']->render('tipos-produtos.html', array('tipos_produtos' => $tipos_produtos, 'titulo' => 'Tipos de produtos'));

});

$app->get('/excluir-tipo-produto', function(Request $request) use ($app) {

    $id_tipo = $request->get('id_tipo');
    $app['db']->delete('tipo_produto', array('id' => $id_tipo));

    return new Response('Tipo de produto excluído com sucesso!', 200);
});

$app->match('/novo-status', function (Request $request) use ($app) {

    $form = $app['form.factory']->createBuilder('form')
        ->add('nome')
        ->getForm();

    #$form->handleRequest($request);
    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();

            $app['db']->insert('status_item_pedido', array(
                'nome' => trim($data['nome'])));
            
            $app['session']->getFlashBag()->add('message', 'O status "' . $data['nome'] . '" foi criado com sucesso!');
        }
    }

    // display the form
    return $app['twig']->render('form-bootstrap.html', array('form' => $form->createView(), 'titulo' => 'Criar novo status de ítem do pedido'));
});

$app->match('/editar-status/{idStatus}', function (Request $request, $idStatus) use ($app) {

    $sql = "SELECT id, nome FROM status_item_pedido WHERE id = ?";
    $status = $app['db']->fetchAssoc($sql, array((int)$idStatus));

    $form = $app['form.factory']->createBuilder('form', $status)
        ->add('id', 'hidden')
        ->add('nome')
        ->getForm();

    #$form->handleRequest($request);
    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();

            $app['db']->update('status', array('nome' => trim($data['nome'])), array('id' => $data['id']));
            
            $app['session']->getFlashBag()->add('message', 'O status #' . $data['id'] . ' foi alterado com sucesso!');
        }
    }

    // display the form
    return $app['twig']->render('form-bootstrap.html', array('form' => $form->createView(), 'titulo' => 'Editar status do ítem do pedido'));
});

$app->get('/status', function() use ($app) {

    $sql = "SELECT id, nome FROM status_item_pedido ORDER BY id ASC";
    $status = $app['db']->fetchAll($sql);

    return $app['twig']->render('status.html', array('statuses' => $status, 'titulo' => 'Todos status de ítens do pedido'));

});

$app->get('/excluir-status', function(Request $request) use ($app) {

    $id_status = $request->get('id_status');
    $app['db']->delete('status_item_pedido', array('id' => $id_status));

    return new Response('Status excluído com sucesso!', 200);
});

//funções auxiliares

function getUserId($app) {
    $token = $app['security']->getToken();
    return $app['db']->fetchColumn("SELECT id FROM usuarios WHERE login = ?", array((string)$token->getUser()->getUsername()), 0);
}

function mandaEmail($app, $assunto, $remetente, $destinatario, $mensagem) {
    $message = \Swift_Message::newInstance();
    $message->setSubject($assunto);
    $message->setFrom(array($remetente));
    $message->setTo($destinatario);

    $message->setBody($mensagem);
    $app['mailer']->send($message);
}

function random_password( $length = 8 ) {
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-=+;:,.?";
    $password = substr( str_shuffle( $chars ), 0, $length );
    return $password;
}