import { env } from '../config/env.js';

// Cargo con tarjeta en Culqi. Monto en CÉNTIMOS.
// Docs: https://apidocs.culqi.com/#tag/Cargos/operation/crear-cargo
export async function createCulqiCharge({ token, amount, email, orderRef }) {
  if (!env.culqiSecretKey) {
    return { success: false, message: 'El pago con tarjeta no está configurado.' };
  }

  try {
    const response = await fetch('https://api.culqi.com/v2/charges', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${env.culqiSecretKey}`,
      },
      body: JSON.stringify({
        amount,
        currency_code: 'PEN',
        email,
        source_id: token,
        description: `Pedido Florería Los Lirios ${orderRef}`,
        metadata: { order_ref: orderRef },
      }),
      signal: AbortSignal.timeout(30_000),
    });

    const data = await response.json().catch(() => ({}));

    if (response.status === 201 && data.id) {
      return { success: true, chargeId: data.id };
    }

    console.error('[Culqi Charge Error]', response.status, JSON.stringify(data));
    return {
      success: false,
      message: data.user_message || data.merchant_message || 'Pago rechazado. Verifica los datos de tu tarjeta.',
    };
  } catch (err) {
    console.error('[Culqi Error]', err.message);
    return { success: false, message: 'Error de conexión con el procesador de pagos.' };
  }
}
