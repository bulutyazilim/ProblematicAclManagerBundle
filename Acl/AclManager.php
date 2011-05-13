<?php

namespace Problematic\AclManagerBundle\Acl;

use Symfony\Component\Security\Core\SecurityContext;
use Symfony\Component\Security\Acl\Dbal\MutableAclProvider;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Domain\UserSecurityIdentity;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Symfony\Component\Security\Acl\Domain\Acl;
use Symfony\Component\Security\Acl\Exception\AclAlreadyExistsException;
use Symfony\Component\Security\Acl\Model\SecurityIdentityInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Role\RoleInterface;
use Symfony\Component\Security\Acl\Domain\RoleSecurityIdentity;

use Problematic\AclManagerBundle\Exception\InvalidIdentityException;

class AclManager {
    protected $securityContext;
    protected $aclProvider;
    protected $maskBuilder;
    protected $contextEntity;
    
    /**
     * @var Acl
     */
    protected $acl;
    
    /**
     * @var SecurityIdentityInterface
     */
    protected $securityIdentity;
    protected $securityIdentityCollection = array();
    
    public function __construct(SecurityContext $securityContext, MutableAclProvider $aclProvider) {
        $this->securityContext = $securityContext;
        $this->aclProvider = $aclProvider;
        $this->maskBuilder = new MaskBuilder();
    }
    
    public function setContextEntity($entity) {
        $this->contextEntity = $entity;
        
        return $this;
    }
    
    public function isAclLoaded() {
        return (null !== $this->acl && $this->acl instanceof Acl);
    }
    
    public function hasSecurityIdentity() {
        return (null !== $this->securityIdentity && $this->securityIdentity instanceof SecurityIdentityInterface);
    }
    
    public function getSecurityIdentities() {
        return $this->securityIdentityCollection;
    }
    public function addSecurityIdentity(SecurityIdentityInterface $securityIdentity) {
        $this->securityIdentityCollection[] = $securityIdentity;
    }
    public function resetSecurityIdentities() {
        $this->securityIdentityCollection = array();
    }
    
    public function loadAcl() {
        $this->acl = $this->doLoadAcl($this->contextEntity);
        
        return $this;
    }
    
    /**
     * @param mixed $entity
     * @return Acl
     */
    protected function doLoadAcl($entity) {
        $objectIdentity = ObjectIdentity::fromDomainObject($entity);
        
        // is this faster than finding, and creating on null?
        try {
            $acl = $this->aclProvider->createAcl($objectIdentity);
        } catch(AclAlreadyExistsException $ex) {
            $acl = $this->aclProvider->findAcl($objectIdentity);
        }
        
        return $acl;
    }
    
    public function createSecurityIdentity($identity) {
        $this->addSecurityIdentity($this->doCreateSecurityIdentity($identity));

        return $this;
    }
    
    /**
     * @param mixed $identity
     * @return SecurityIdentityInterface 
     */
    protected function doCreateSecurityIdentity($identity) {
        if( is_string($identity)) {
            $identity = new Role($identity);
        }

        if( !($identity instanceof UserInterface) && !($identity instanceof TokenInterface) && !($identity instanceof RoleInterface) ) {
            throw new InvalidIdentityException('$identity must implement one of: UserInterface, TokenInterface, RoleInterface (' . get_class($identity) . ' given)');
        }
        
        $securityIdentity = null;
        if( $identity instanceof UserInterface ) {
            $securityIdentity = UserSecurityIdentity::fromAccount($identity);
        } else if( $identity instanceof TokenInterface ) {
            $securityIdentity = UserSecurityIdentity::fromToken($identity);
        } else if( $identity instanceof RoleInterface ) {
            $securityIdentity = new RoleSecurityIdentity($identity);
        }

        if( null === $securityIdentity || !($securityIdentity instanceof SecurityIdentityInterface) ) {
            throw new InvalidIdentityException('Couldn\'t create a valid SecurityIdentity with the provided identity information');
        }
        
        return $securityIdentity;
    }
    
    /**
     * @param mixed $entity 
     */
    public function installDefaultAccess() {
        $this->doInstallDefaultAccess($this->contextEntity);
        
        return $this;
    }
    
    protected function doInstallDefaultAccess($entity) {
        $acl = $this->doLoadAcl($entity);
        
        $builder = $this->getMaskBuilder();

        $builder->add('iddqd');
        $this->doSetPermission('class', $acl, array(
            'mask'              => $builder->get(),
            'securityIdentity'  => new RoleSecurityIdentity('ROLE_SUPER_ADMIN'),
        ));

        $builder->reset();
        $builder->add('master');
        $this->doSetPermission('class', $acl, array(
            'mask'              => $builder->get(),
            'securityIdentity'  => new RoleSecurityIdentity('ROLE_ADMIN'),
        ));

        $builder->reset();
        $builder->add('view');
        $this->doSetPermission('class', $acl, array(
            'mask'              => $builder->get(),
            'securityIdentity'  => new RoleSecurityIdentity('IS_AUTHENTICATED_ANONYMOUSLY'),
        ));

        $builder->reset();
        $builder->add('create');
        $builder->add('view');
        $this->doSetPermission('class', $acl, array(
            'mask'              => $builder->get(),
            'securityIdentity'  => new RoleSecurityIdentity('ROLE_USER'),
        ));
        
        return true;
    }
    
    public function setObjectPermission($mask, $granting = true) {
        $this->doSetPermission('object', $this->acl, array(
            'mask'              => $mask,
            'securityIdentity'  => $this->securityIdentity,
            'granting'          => $granting,
        ));
        
        return $this;
    }
    
    public function setClassPermission($mask, $granting = true) {
        $this->doSetPermission('class', $this->acl, array(
            'mask'              => $mask,
            'securityIdentity'  => $this->securityIdentity,
            'granting'          => $granting,
        ));
        
        return $this;
    }
    
    /**
     * Takes an ACE type (class/object), an ACL and an array of settings (mask, identity, granting, index)
     * Loads an ACE collection from the ACL and updates the permissions (creating if no appropriate ACE exists)
     * 
     * @todo refactor this code to transactionalize ACL updating
     * 
     * @param string $type
     * @param array $aceCollection
     * @param array $args 
     */
    protected function doSetPermission($type, Acl $acl, array $args) {
        $defaults = array(
            'mask'              => 0,
            'securityIdentity'  => $this->securityIdentity,
            'granting'          => true,
            'index'             => 0,
        );
        $settings = array_merge($defaults, $args);
        
        $aceCollection = call_user_func(array($acl, "get{$type}Aces"));
        $aceFound = false;
        $doInsert = false;
        
        //we iterate backwards because removing an ACE reorders everything after it, which will cause unexpected results when iterating forward
        for ($i=count($aceCollection)-1; $i>=0; $i--) {
            if ($aceCollection[$i]->getSecurityIdentity() === $settings['securityIdentity']) {
                if ($aceCollection[$i]->isGranting() === $settings['granting']) {
                    call_user_func(array($acl, "update{$type}Ace"), $i, $settings['mask']);
                } else {
                    call_user_func(array($acl, "delete{$type}Ace"), $i);
                    $doInsert = true;
                }
                $aceFound = true;
            }
        }
        
        if ($doInsert || !$aceFound) {
            call_user_func(array($acl, "insert{$type}Ace"),
                    $settings['securityIdentity'], $settings['mask'], $settings['index'], $settings['granting']);
        }
        
        $this->aclProvider->updateAcl($acl);
    }
    
    public function getMaskBuilder() {
        return $this->maskBuilder->reset();
    }
}

?>
