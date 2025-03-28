<?php

namespace App\Controller;

use App\Message\LuckyNumberNotification;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class LuckyController
{
    #[Route('/lucky/number', 'lucky_number')]
    public function number(MessageBusInterface $bus): Response
    {
        $number = \random_int(0, 100);

        $bus->dispatch(new LuckyNumberNotification($number));

        return new Response("$number");
    }

    #[Route('/lucky/fail', 'lucky_fail')]
    public function fail(MessageBusInterface $bus): Response
    {
        $bus->dispatch(new LuckyNumberNotification(101));

        return new Response("101");
    }
}
