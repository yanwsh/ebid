<?php
/**
 * Created by PhpStorm.
 * User: yanwsh
 * Date: 11/29/14
 * Time: 10:02 PM
 */

namespace ebid\Event;

use Symfony\Component\EventDispatcher\Event;
use ebid\Event\RegisterEvent;

class EmailListener {

    public function sendEmailOnRegistration(RegisterEvent $event){
        global $container;
        $user = $event->getUser();
        $message = \Swift_Message::newInstance($user->getUsername() . ', Welcome to ebid')
            ->setFrom('IADProject_ebid@yahoo.com', 'ebid')
            ->setTo(array($user->getEmail() => $user->getUsername()))
            ->setBody('Hello ' . $user->getUsername() .', Thank you for your registration.');

        $mailer = $container->get('swiftMailer');
        $result = $mailer->send($message);
    }

    public function sendEmailOnBidFinish(BidResultEvent $event){
        global $container;
        $winlists = $event->getWinLists();
        $loselists = $event->getLoseLists();

        if($winlists != null){
            foreach($winlists as $winner){
                $message = \Swift_Message::newInstance($winner['username'] . ', Congratulations, it\'s all yours!')
                    ->setFrom('IADProject_ebid@yahoo.com', 'ebid')
                    ->setTo(array($winner['email'] => $winner['username']))
                    ->setBody('Congratulations, ' . $winner['username'] .'.  You win the product: ' . $winner['pname']);

                $mailer = $container->get('swiftMailer');
                $result = $mailer->send($message);
            }
        }

        if($loselists != null){
            foreach($loselists as $loser){
                $message = \Swift_Message::newInstance($loser['username'] . ', Thank you for your participation')
                    ->setFrom('IADProject_ebid@yahoo.com', 'ebid')
                    ->setTo(array($loser['email'] => $loser['username']))
                    ->setBody('Sorry, ' . $loser['username'] .', Thank you for your participation. You didn\'t win the product '. $loser['pname']);

                $mailer = $container->get('swiftMailer');
                $result = $mailer->send($message);
            }
        }

    }
} 