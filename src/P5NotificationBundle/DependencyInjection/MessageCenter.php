<?php

/**
 * Created by PhpStorm.
 * User: thaiht
 * Date: 10/8/15
 * Time: 3:28 PM
 */

namespace P5NotificationBundle\DependencyInjection;

use Doctrine\ORM\EntityManager;
use P5\Model\Message;
use P5\Model\MessageUser;
use P5\Model\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Doctrine\Common\Collections\ArrayCollection;

class MessageCenter
{
    private $em;
    private $token;
    public function __construct(EntityManager $em, TokenStorage $token){
        $this->em = $em;
        $this->token = $token;
    }

    public function pushMessage($from, $content, $type, $to = array()){
        $message = new Message();
        $message->setUser($from);
        $message->setContent($content);
        $message->setType($type);
        $toUsers = new ArrayCollection();
        if(count($to) > 0){
            foreach($to as $u) {
                $messageUser = new MessageUser();
                $messageUser->setToUser($u);
                $messageUser->setMessage($message);
                $messageUser->setStatus(false);
                $this->em->persist($messageUser);
                $toUsers->add($messageUser);
            }
        }
        else{
            $userRepository = $this->em->getRepository('P5:User');
            $users = $userRepository->findAll();
            foreach($users as $u) {
                $messageUser = new MessageUser();
                $messageUser->setToUser($u);
                $messageUser->setMessage($message);
                $messageUser->setStatus(false);
                $this->em->persist($messageUser);
                $toUsers->add($messageUser);
            }
        }
        $message->setReceivedUsers($toUsers);
        $message->setSentTime(new \DateTime());
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function getNotificationNumber(){
        $user = $this->token->getToken()->getUser();
        return count($user->getReceivedMessages());
    }

    public function getNotifications(){
        $user = $this->token->getToken()->getUser();
        return $user->getReceivedMessages();
    }
}