// Uso: npm run hash -- "MiNuevaContraseña"
// Imprime el hash bcrypt para pegarlo en la columna users.password
import bcrypt from 'bcryptjs';

const password = process.argv[2];
if (!password) {
  console.error('Uso: npm run hash -- "tu-contraseña"');
  process.exit(1);
}
console.log(await bcrypt.hash(password, 10));
