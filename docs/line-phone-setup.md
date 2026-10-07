# Customer line phone setup

`/lines/{number}` lets customers choose Zoiper, Yealink SIP desk phones, or another SIP device. Selecting a device changes the instructions and configuration field names. Selecting an enabled tenant extension changes the username, authentication ID, and link to its credentials. Only the device preference is stored in browser storage; credentials are never stored there.

New-person and additional-phone forms offer an editable extension suggestion. Extensions accept 3–9 digits without a leading zero. Persian and Arabic digits normalize to ASCII before validation and storage. The suggestion is the first unused number in 2000–9999. Customers may enter another valid number. Existing setup callers that omit the number use this deterministic suggestion.

The current directory authenticates using a globally unique extension. Preserve both the global database constraint and tenant ownership checks. Unavailable numbers receive a generic validation error without identifying their owner. The database constraint handles competing reservations; a conflicting save returns validation feedback. Disabled extensions remain reserved. Creating a phone does not automatically change the line's inbound answerer or outbound caller ID.

Credentials retain their existing one-time session delivery. Passwords start masked and can be revealed or copied. After creation or reset, the redirect opens the connection section. Page responses remain private and non-cacheable.

Yealink instructions apply to SIP desk phones such as T3, T4 and T5 models; menu labels vary by firmware. Device management credentials differ from Blucom SIP credentials. Enter the extension in both User Name and Register Name, and use the configured customer SIP host and port in separate fields. UDP and no outbound proxy describe the existing direct customer connection, rather than the provider trunk.

Reference documentation:

- [Yealink account registration](https://support.yealink.com/docs/sip-t58w/account-registration/0d20b03058174a31baeaa3e2d5bda4a7)
- [Yealink manual setup](https://www.yealink.com/en/onepage/yealink-phone-manual-provisioning-for-yealink-phones) (its provider-specific proxy settings do not apply to Blucom's direct registration)
- [Zoiper account configuration](https://www.zoiper.com/en/support/answer/for/android/108/Configuring_a_VoIP_account)

Validation: purchased-line, setup wizard, customer setup, and FreeSWITCH XML feature tests; production asset build; desktop and mobile browser checks for device switching, field mapping, copying, password controls, saved preference, and layout overflow. A physical Yealink registration and inbound/outbound call test remains an operational acceptance check. No FreeSWITCH configuration changes or reloads are involved.
