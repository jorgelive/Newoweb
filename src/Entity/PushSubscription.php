<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\PushSubscriptionRepository;

/**
 * Entidad que representa una suscripción de un navegador (Service Worker) a las notificaciones WebPush.
 * * ¿Por qué existe?: Actúa como el puente persistente entre el servidor de Symfony y el dispositivo físico
 * del usuario. Almacena las credenciales criptográficas únicas generadas por el navegador (endpoint, p256dh y auth)
 * que son obligatorias para encriptar y despachar los payloads Push a través de los servidores de Google/Apple/Mozilla
 * cuando el usuario tiene la PWA cerrada.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscription')]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Relación con el usuario propietario de la suscripción.
     * Si el usuario se elimina del sistema, sus suscripciones Push se eliminan en cascada.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * URL única del servidor de notificaciones del proveedor del navegador (ej. FCM de Google).
     */
    #[ORM\Column(type: 'text')]
    private ?string $endpoint = null;

    /**
     * Llave pública P-256 (Diffie-Hellman) generada localmente por el navegador del cliente.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $p256dhKey = null;

    /**
     * Secreto de autenticación generado localmente por el navegador del cliente.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $authToken = null;

    /**
     * Obtiene explícitamente el identificador único de la suscripción en la base de datos.
     * * ¿Por qué existe?: Permite a Doctrine y a otros servicios referenciar esta entidad
     * de manera única, especialmente útil al iterar y buscar suscripciones expiradas para purgarlas.
     * * @return int|null El ID numérico, o null si la entidad aún no ha sido persistida.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Obtiene explícitamente el usuario propietario de esta suscripción Push.
     * * ¿Por qué existe?: Es crítico para la lógica de negocio que decide a qué dispositivo
     * enviar la notificación. Permite filtrar las suscripciones por usuario destinatario.
     * * @return User|null El objeto del usuario asociado.
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Establece explícitamente el usuario propietario de la suscripción.
     * * ¿Por qué existe?: Se utiliza en el controlador al registrar una nueva suscripción
     * o al actualizar una existente si el dispositivo cambia de sesión, asegurando que
     * la notificación llegue al inquilino correcto.
     * * @param User $user La entidad del usuario autenticado.
     * @return self
     */
    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Obtiene explícitamente la URL del endpoint del proveedor Push.
     * * ¿Por qué existe?: El servicio despachador de WebPush necesita esta URL exacta
     * para saber a qué servidor de Google, Mozilla o Apple debe enviar el paquete encriptado.
     * * @return string|null La URL completa del endpoint.
     */
    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    /**
     * Establece explícitamente la URL del endpoint generada por el navegador.
     * * ¿Por qué existe?: El Service Worker genera esta URL dinámicamente. Este método
     * permite almacenar ese dato dinámico en la base de datos durante el proceso de registro.
     * * @param string $endpoint La URL del servicio Push.
     * @return self
     */
    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    /**
     * Obtiene explícitamente la llave pública p256dh del navegador.
     * * ¿Por qué existe?: Es un requerimiento criptográfico del estándar WebPush.
     * El backend necesita esta llave para encriptar el payload del mensaje, garantizando
     * que solo el navegador específico que generó la llave pueda desencriptarlo y leerlo.
     * * @return string|null La llave pública en formato Base64.
     */
    public function getP256dhKey(): ?string
    {
        return $this->p256dhKey;
    }

    /**
     * Establece explícitamente la llave pública p256dh.
     * * ¿Por qué existe?: Para persistir la llave criptográfica que Axios envía
     * desde el frontend en el momento de la suscripción.
     * * @param string $p256dhKey La llave pública proporcionada por la API del navegador.
     * @return self
     */
    public function setP256dhKey(string $p256dhKey): self
    {
        $this->p256dhKey = $p256dhKey;

        return $this;
    }

    /**
     * Obtiene explícitamente el token de autenticación del navegador.
     * * ¿Por qué existe?: Es el segundo componente criptográfico requerido por el estándar WebPush.
     * Funciona como un secreto compartido que valida la integridad del mensaje enviado.
     * * @return string|null El secreto de autenticación.
     */
    public function getAuthToken(): ?string
    {
        return $this->authToken;
    }

    /**
     * Establece explícitamente el token de autenticación.
     * * ¿Por qué existe?: Para almacenar el secreto generado por el navegador
     * y poder firmar correctamente las notificaciones futuras.
     * * @param string $authToken El token generado por el PushManager del cliente.
     * @return self
     */
    public function setAuthToken(string $authToken): self
    {
        $this->authToken = $authToken;

        return $this;
    }
}