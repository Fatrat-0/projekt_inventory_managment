import { execFileSync } from 'node:child_process';

// Opening stock from the time before manual receipts required putaway.
export function seedUsableStock(data) {
    return Number(execFileSync('php', ['tests/Browser/seed-usable-stock.php', Buffer.from(JSON.stringify(data)).toString('base64')], { encoding: 'utf8' }));
}
