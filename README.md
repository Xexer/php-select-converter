# ABAP Select Converter (PHP backend)

Backend version of the [ABAP Select Converter](https://software-heroes.com/en/abap-select-converter), that's also used in [MIA](https://github.com/Xexer/my-ide-actions) for the SELECT conversion.

## Conversion
The converter actual converts a Open SQL statement to ABAP SQL for modernization purpose. In the next step all tables and fiels are exchanges by the released Core Data Services to use the statement in ABAP Cloud.

```ABAP
SELECT * FROM bkpf
  INTO CORRESPONDING FIELDS OF TABLE rt_result
  WHERE ( bukrs = '0139'
     OR bukrs = '0140' )
    AND gjahr = p_gjahr.
```

The final result of the conversation should look like this example.

```ABAP
SELECT FROM I_JOURNALENTRY
  FIELDS *
  WHERE ( CompanyCode = '0139' 
    OR CompanyCode = '0140' ) 
    AND FiscalYear = @p_gjahr 
  INTO CORRESPONDING FIELDS OF TABLE @rt_result.
```
