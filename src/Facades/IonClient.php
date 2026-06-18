<?php

namespace Ptpn\IonClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array checkSession(string $sessionId)
 * @method static array verify(string $code)
 * @method static array getSessionFullInfo(string $sessionId)
 * @method static array getUserRoles(string $sessionId, string|null $application = null)
 * @method static array heartbeat(string $sessionId)
 * @method static array logout(string $sessionId)
 * @method static bool isEnabled()
 * @method static \Illuminate\Http\RedirectResponse callback(\Illuminate\Http\Request $request)
 *
 * @see \Ptpn\IonClient\IonClient
 */
class IonClient extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \Ptpn\IonClient\IonClient::class;
    }
}
