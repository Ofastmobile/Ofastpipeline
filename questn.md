REST API
Identity Verification API
1
Authentication
curl -X GET https://api.prembly.com/v1/auth/verify \
  -H "x-api-key: live_sk_3335ec1bf3984964adf282f4570b513c" \
  -H "Content-Type: application/json"

Copy
2
Execute Verification (e.g. BVN)
curl -X POST https://api.prembly.com/identitypass/verification/bvn \
  -H "x-api-key: live_sk_3335ec1bf3984964adf282f4570b513c" \
  -H "Content-Type: application/json" \
  -d '{
    "number": "12345678901"
  }'

Copy
3
Handle Response
{
  "status": true,
  "detail": "Verification Successful",
  "data": {
    "first_name": "JOHN",
    "last_name": "DOE",
    "dob": "1990-01-01",
    "verification_reference": "REF_123456"
  }
}








HTML / CDN Integration
1
Setup
<!-- Add to your HTML <head> or before </body> -->
<script src="https://js.prembly.com/v1/inline/widget-v3.js"></script>

Copy
2
Usage
<script>
 function startVerification(userData) {
      console.log('Starting verification with data:', userData);

      IdentityKYC.verify({
        // Widget credentials
        widget_id: "YOUR_CONFIG_ID",
        widget_key: "live_pk_15486977d2394706b1407279a5ae92fc",
        
        // User information
        first_name: userData.firstName,
        last_name: userData.lastName,
        email: userData.email,
        phone: userData.phone,
        
        // Callback function to handle verification results
        callback: (response) => console.log(response)
      });
 }
</script>




Secret Key
For server-side authentication
live_sk_3335ec1bf3984964adf282f4570b513c

Public Key
For client-side integrations
live_pk_15486977d2394706b1407279a5ae92fc




Full documentation
https://docs.prembly.com/docs/authentication



Great, you're all set to start integrating! Here's how to get your API keys and begin making verification calls:

1. Get Your API Keys
Log into app.prembly.com

Navigate to the Integration module

Copy your test keys (for sandbox) or live keys (for production)


2. Authenticate Your Requests
Include your Secret API key in every request header as:

x-api-key: YOUR_SECRET_KEY


3. API Base URL
https://api.prembly.com


For your use case (CAC, BVN, NIN), your calls will follow this pattern:
BVN: POST https://api.prembly.com/verification/bvn

NIN & CAC endpoints are listed in the full API docs


4. API Documentation
Full endpoint details, request/response examples, and code samples are available at docs.prembly.com

Note: Since your account is newly verified, you'll be in sandbox mode by default — great for testing before going live.

Would you like guidance on a specific endpoint (CAC, BVN, or NIN)?