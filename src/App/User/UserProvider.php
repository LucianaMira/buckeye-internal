<?php
// src/App/User/UserProvider.php
namespace App\User;
 
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\User;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Doctrine\DBAL\Connection;
 
class UserProvider implements UserProviderInterface
{
    private $conn;
 
    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }
 
    public function loadUserByUsername($username)
    {
        $stmt = $this->conn->executeQuery('SELECT u.login, u.senha, t.tipo AS tipo_usuario FROM usuarios u INNER JOIN tipo_usuario t ON u.tipo_usuario = t.id WHERE login = ?', array(strtolower($username)));
        if (!$user = $stmt->fetch()) {
            throw new UsernameNotFoundException(sprintf('Username "%s" does not exist.', $username));
        }
 
        return new User($user['login'], $user['senha'], array($user['tipo_usuario']), true, true, true, true);
    }
 
    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }
 
        return $this->loadUserByUsername($user->getUsername());
    }
 
    public function supportsClass($class)
    {
        return $class === 'Symfony\Component\Security\Core\User\User';
    }
}