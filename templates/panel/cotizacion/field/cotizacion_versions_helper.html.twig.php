{# templates/panel/cotizacion/field/cotizacion_versions_helper.html.twig #}

{% if field.value is empty %}
<span class="badge badge-secondary">Aún no hay versiones generadas</span>
{% else %}
<div class="table-responsive">
    <table class="table table-sm table-bordered table-hover mt-2">
        <thead class="thead-light">
        <tr>
            <th scope="col" class="text-center">Versión</th>
            <th scope="col">Fecha de Creación</th>
            <th scope="col">Expiración</th>
            <th scope="col" class="text-center">Moneda</th>
            <th scope="col" class="text-right">Total Venta</th>
            <th scope="col" class="text-center">Estado</th>
        </tr>
        </thead>
        <tbody>
        {% for cotizacion in field.value %}
        <tr>
            <td class="text-center align-middle">
                <span class="badge badge-info fs-6">V{{ cotizacion.version }}</span>
            </td>
            <td class="align-middle">
                {{ cotizacion.createdAt ? cotizacion.createdAt|date('d/m/Y H:i') : '-' }}
            </td>
            <td class="align-middle">
                {% if cotizacion.fechaExpiracion %}
                {% set is_expired = date(cotizacion.fechaExpiracion) < date() %}
                <span class="{{ is_expired ? 'text-danger fw-bold' : 'text-success' }}">
                                    {{ cotizacion.fechaExpiracion|date('d/m/Y') }}
                                    {{ is_expired ? '(Expirada)' : '' }}
                                </span>
                {% else %}
                <span class="text-muted">Sin caducidad</span>
                {% endif %}
            </td>
            <td class="text-center align-middle fw-bold text-muted">
                {{ cotizacion.monedaGlobal }}
            </td>
            <td class="text-right align-middle fw-bold text-primary">
                {{ cotizacion.totalVenta|number_format(2, '.', ',') }}
            </td>
            <td class="text-center align-middle">
                <a href="{{ ea_url()
                                .setController('App\\Cotizacion\\Controller\\Crud\\CotizacionCrudController')
                                .setAction('detail')
                                .setEntityId(cotizacion.id) }}"
                   class="btn btn-sm btn-outline-secondary"
                   title="Ver Detalle de esta Versión">
                    <i class="fa fa-eye"></i> Auditar
                </a>
            </td>
        </tr>
        {% endfor %}
        </tbody>
    </table>
</div>
{% endif %}