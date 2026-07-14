<?php

declare(strict_types=1);

/**
 * Ana dizin kısayolu.
 *
 * Uygulama gerçekte `public/` kökünden, kalıcı Apache vhost'unda (port 8081,
 * DocumentRoot=public/) sunulur. Panel tüm bağlantı ve fetch çağrılarında mutlak
 * yol (`/panel/...`, `/services`) kullandığı için alt klasörde çalışamaz; bu yüzden
 * `http://localhost/berber-whatsapp-otomasyon/` adresine giren kullanıcıyı doğrudan
 * çalışan panele yönlendiriyoruz. Bu dosya Apache'nin varsayılan DirectoryIndex'i
 * olduğundan, dizin listesi yerine anında bu yönlendirme devreye girer.
 */

header('Location: http://localhost:8081/panel/login', true, 302);
exit;
