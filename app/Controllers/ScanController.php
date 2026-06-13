<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\AssetService;

class ScanController
{
    public function __construct(private AssetService $assets)
    {
    }

    public function show(string $token): void
    {
        $data = $this->assets->getScanData($token);
        if ($data === null) {
            Response::abort(404, 'ไม่พบ QR token หรือ Asset ที่เกี่ยวข้อง');
        }

        $layout = auth()->check() ? 'app' : 'guest';

        Response::view('scan/show', [
            'title' => 'Scan Asset QR',
            'pageHeading' => 'สแกน QR ทรัพย์สิน',
            'currentUser' => auth()->user() ?? [],
            'asset' => $data['asset'],
            'ticketCreatePath' => $data['ticket_create_path'],
            'loginPath' => $data['login_path'],
            'isAuthenticated' => auth()->check(),
        ], $layout);
    }
}
