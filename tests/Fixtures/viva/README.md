# Viva sandbox reconciliation fixtures

Verified on 2026-09-15 against the configured Leonidas Viva **demo** account,
using existing order codes from its local payment logs. No payment was created.
Two successful payment searches and their OAuth Retrieve Transaction calls
returned HTTP 200, matching order codes, status F and EUR (978).

These JSON files are deliberately limited projections of those actual responses:
only reconciliation fields are retained. Order/transaction identifiers, amount
and timestamp are replaced with test values. Customer, merchant, card details
and credentials are excluded. Field nesting and retained field types match the
sandbox response. In particular, lookup association is `Order.OrderCode` and
`TransactionId` belongs to each element of `Transactions`, not Retrieve Order.

- Search: `GET https://demo.vivapayments.com/api/transactions/?ordercode={code}`,
  Basic authentication with Merchant ID + API Key. Envelope: `Success`,
  `ErrorCode`, `Transactions`. An unknown code returned an empty successful list.
- Verify: `GET https://demo-api.vivapayments.com/checkout/v2/transactions/{id}`,
  OAuth2 client credentials from `https://demo-accounts.vivapayments.com/connect/token`.
- `GET /api/orders/{code}` returned order metadata and no TransactionId.

Official reference: https://developer.viva.com/apis-for-payments/payment-api/
(the search is under **Retrieve transactions (OLD)** and marked deprecated).
Basic authentication: https://developer.viva.com/integration-reference/basic-auth/

Sandbox support does not prove LIVE account permissions or future availability
of the deprecated lookup. Verify this same complete flow with a LIVE transaction.
