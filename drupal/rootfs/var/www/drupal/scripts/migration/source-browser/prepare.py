"""Export the archived three-tab Gunn workbook using Python standard libraries."""
from pathlib import Path
from zipfile import ZipFile
import xml.etree.ElementTree as E
import csv
base = Path(__file__).resolve().parent
ns={'m':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
with ZipFile(base/'gunn-index.xlsx') as z:
    strings=[''.join(n.itertext()) for n in E.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si',ns)]
    rels={n.attrib['Id']:n.attrib['Target'] for n in E.fromstring(z.read('xl/_rels/workbook.xml.rels'))}
    for sheet in E.fromstring(z.read('xl/workbook.xml')).findall('m:sheets/m:sheet',ns):
        rid=sheet.attrib['{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id']
        target=rels[rid].lstrip('/')
        if not target.startswith('xl/'): target='xl/'+target
        rows=[]
        for row in E.fromstring(z.read(target)).findall('m:sheetData/m:row',ns):
            values=[]
            for cell in row.findall('m:c',ns):
                letters=''.join(c for c in cell.attrib['r'] if c.isalpha())
                col=0
                for letter in letters: col=col*26+ord(letter)-64
                while len(values)<col: values.append('')
                val=cell.find('m:v',ns)
                if cell.attrib.get('t')=='s': value=strings[int(val.text)] if val is not None else ''
                elif cell.attrib.get('t')=='inlineStr': value=''.join(cell.find('m:is',ns).itertext())
                else: value=val.text if val is not None else ''
                values[col-1]=value
            if not rows:
                while values and not values[-1]: values.pop()
            else:
                if any(values[len(rows[0]):]):
                    raise ValueError('Nonempty data outside named columns')
                values = values[:len(rows[0])]
                values += [''] * (len(rows[0]) - len(values))
            values = [value[:-2] if value.endswith('.0') and value[:-2].isdigit() else value for value in values]
            rows.append(values)
        with (base/(sheet.attrib['name']+'.csv')).open('w',newline='') as f: csv.writer(f, lineterminator='\n').writerows(rows)
        print(sheet.attrib['name'],len(rows)-1,'records; headers:',rows[0],'; sample:',rows[1:3])
