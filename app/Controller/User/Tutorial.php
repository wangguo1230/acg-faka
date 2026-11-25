<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserVisitor::class])]
class Tutorial extends User
{
    public function index(): string
    {
        return $this->binance();
    }

    public function binance(): string
    {
        return $this->theme('买币教程', 'TUTORIAL', 'Tutorial/Binance.html', ['from' => 0]);
    }
}
